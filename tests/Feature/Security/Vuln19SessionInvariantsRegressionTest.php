<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Tests\Feature\Security;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Juanoecr\StatefulChunkingUpload\Core\Contracts\StateRepositoryInterface;
use Juanoecr\StatefulChunkingUpload\Core\ValueObjects\ChunkHash;
use Juanoecr\StatefulChunkingUpload\Core\ValueObjects\SessionId;
use Juanoecr\StatefulChunkingUpload\Core\ValueObjects\SessionOwner;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Entities\ChunkSession;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Enums\SessionStatus;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Exceptions\UploadBudgetExceededException;
use Juanoecr\StatefulChunkingUpload\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * VULN-19 REGRESSION TEST: Aggregate invariants of a chunk session
 *
 * Three findings that share a shape — the aggregate root held a rule it did not
 * actually enforce.
 *
 * AF-005: fingerprint reuse compared the fingerprint and the owner, and nothing else.
 * A client deriving one fingerprint per user or per batch instead of per file — the
 * ordinary mistake in a multi-file uploader — received 201 "Session initiated
 * successfully" describing the *previous* file. Its chunks then overwrote the first
 * file's by index and /complete failed integrity verification, losing both files and
 * blaming the wrong one.
 *
 * AF-007: the byte budget was decided on a snapshot read outside the lock that
 * increments the counter. N concurrent uploads of distinct indices all read the same
 * uploadedBytes and all passed, overshooting by up to (N-1) chunks.
 *
 * AF-008: emptiness was tested with trim(), which strips NUL, so an all-zero raw-body
 * chunk — ordinary in a sparse file, a disk image or a padded binary — was rejected as
 * empty despite carrying a full payload and a valid hash.
 *
 * Security Invariants:
 * 1. A fingerprint is only a resume token when the declaration, the owner and the
 *    status all still agree. Otherwise it names a new session.
 * 2. The byte budget is verified inside the same critical section that increments the
 *    counter, so it cannot be raced.
 * 3. "Empty" means no bytes arrived, never "the bytes look like whitespace".
 */
class Vuln19SessionInvariantsRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        foreach (['initiate', 'upload', 'status', 'complete', 'cancel'] as $op) {
            Config::set("stateful-chunking-upload.rate_limits.{$op}", 1000);
        }
    }

    /**
     * @return array{name: string, body: string, size: int, hash: string}
     */
    private function file(string $name, string $body): array
    {
        return [
            'name' => $name,
            'body' => $body,
            'size' => strlen($body),
            'hash' => hash('sha256', $body),
        ];
    }

    /**
     * @param  array{name: string, body: string, size: int, hash: string}  $file
     */
    private function initiate(array $file, string $fingerprint, int $totalChunks = 1): TestResponse
    {
        return $this->postJson('/api/chunks/initiate', [
            'file_name' => $file['name'],
            'file_size' => $file['size'],
            'total_chunks' => $totalChunks,
            'total_hash' => $file['hash'],
            'fingerprint' => $fingerprint,
        ]);
    }

    // ------------------------------------------------------------------ AF-005

    /**
     * REGRESSION 1: the probe that found this, inverted.
     *
     * Two different files under one fingerprint used to collapse into one session. Now
     * each gets its own, and crucially the second response describes the file that was
     * actually asked for.
     */
    public function test_the_same_fingerprint_with_a_different_file_yields_a_new_session(): void
    {
        $alpha = $this->file('alpha.txt', 'ALPHA CONTENT');
        $beta = $this->file('beta.txt', 'BETA CONTENT WHICH IS LONGER');

        $first = $this->initiate($alpha, 'user-42-drop');
        $first->assertStatus(201);

        $second = $this->initiate($beta, 'user-42-drop');
        $second->assertStatus(201);

        $this->assertNotSame(
            $first->json('data.session_id'),
            $second->json('data.session_id'),
            'A fingerprint that now names a different file is not the same fingerprint'
        );

        // The response must describe what the caller declared, not what came before it.
        $this->assertSame('beta.txt', $second->json('data.file_name'));
        $this->assertSame($beta['size'], $second->json('data.file_size'));
    }

    /**
     * REGRESSION 2: resume still works. The fix must not have turned every re-initiate
     * into a new session, which would break the resumable-upload feature outright.
     */
    public function test_an_identical_declaration_still_resumes_the_same_session(): void
    {
        $file = $this->file('resumable.bin', 'PART ONE PART TWO');

        $first = $this->initiate($file, 'resume-me', 2);
        $first->assertStatus(201);
        $sessionId = (string) $first->json('data.session_id');

        // Upload one of the two chunks, leaving the session UPLOADING.
        $this->call('POST', '/api/chunks/upload', [
            'session_id' => $sessionId,
            'chunk_index' => 0,
            'chunk_hash' => hash('sha256', 'PART ONE '),
        ], [], ['file' => UploadedFile::fake()->createWithContent('c0.tmp', 'PART ONE ')])->assertStatus(200);

        $second = $this->initiate($file, 'resume-me', 2);
        $second->assertStatus(201);

        $this->assertSame($sessionId, $second->json('data.session_id'));
        $this->assertSame('uploading', $second->json('data.status'));
        $this->assertSame([1], $second->json('data.pending_chunks'));
    }

    /**
     * REGRESSION 3: each mismatching field on its own is enough to refuse the resume.
     *
     * Checking them as a set matters: a client that recomputes one value and not the
     * others would otherwise slip through whichever comparison was omitted.
     *
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('mismatchingDeclarations')]
    public function test_any_mismatching_field_refuses_the_resume(array $override): void
    {
        $base = $this->file('base.bin', 'BASE CONTENT HERE');

        $first = $this->initiate($base, 'partial-match', 2);
        $first->assertStatus(201);

        $payload = array_merge([
            'file_name' => $base['name'],
            'file_size' => $base['size'],
            'total_chunks' => 2,
            'total_hash' => $base['hash'],
            'fingerprint' => 'partial-match',
        ], $override);

        $second = $this->postJson('/api/chunks/initiate', $payload);
        $second->assertStatus(201);

        $this->assertNotSame($first->json('data.session_id'), $second->json('data.session_id'));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function mismatchingDeclarations(): array
    {
        $body = 'BASE CONTENT HERE';

        return [
            'a different file name' => [['file_name' => 'renamed.bin']],
            'a different declared size' => [['file_size' => strlen($body) + 999]],
            'a different total hash' => [['total_hash' => hash('sha256', $body.'!')]],
            'a different chunk count' => [['total_chunks' => 1]],
        ];
    }

    /**
     * REGRESSION 4: a session with no owner is not resumable by a fingerprint match.
     *
     * Complements Vuln17: there the unowned session was unreachable through the guard,
     * here it is unreachable through the reuse path, which is the second reader of
     * ownership and the one that used to disagree with the first.
     */
    public function test_an_unowned_session_is_never_handed_back_by_fingerprint(): void
    {
        /** @var StateRepositoryInterface $repository */
        $repository = app(StateRepositoryInterface::class);

        $body = 'ORPHANED SESSION CONTENT';
        $unowned = new ChunkSession(
            sessionId: SessionId::generate(),
            fileName: 'unowned.bin',
            fileSize: strlen($body),
            totalChunks: 1,
            totalHash: ChunkHash::fromString(hash('sha256', $body)),
            fingerprint: 'orphan-fingerprint',
            status: SessionStatus::PENDING,
            ownerId: null
        );
        $repository->saveSession($unowned);

        $response = $this->initiate($this->file('unowned.bin', $body), 'orphan-fingerprint');
        $response->assertStatus(201);

        $this->assertNotSame(
            $unowned->sessionId->value,
            $response->json('data.session_id'),
            'A session that belongs to nobody must not be adopted by a fingerprint match'
        );
    }

    // ------------------------------------------------------------------ AF-007

    /**
     * REGRESSION 5: the budget holds inside the lock, against the state read there.
     *
     * Driven at the repository rather than over HTTP on purpose. Over HTTP the check
     * outside the lock catches the second upload first, so an end-to-end test would
     * pass whether or not the in-lock verification exists. Calling updateChunkStatus
     * directly reproduces exactly what two concurrent Actions do: each decided on the
     * same snapshot before either had written.
     */
    public function test_the_byte_budget_cannot_be_exceeded_by_two_writers_deciding_on_one_snapshot(): void
    {
        Config::set('stateful-chunking-upload.chunk_size_bytes', 10);

        /** @var StateRepositoryInterface $repository */
        $repository = app(StateRepositoryInterface::class);

        $session = new ChunkSession(
            sessionId: SessionId::generate(),
            fileName: 'tight.bin',
            fileSize: 10,
            totalChunks: 3,
            totalHash: ChunkHash::fromString(hash('sha256', 'tight')),
            fingerprint: 'tight-budget',
            status: SessionStatus::PENDING,
            ownerId: SessionOwner::fromString('user:99')
        );
        $repository->saveSession($session);

        // budget = min(maxFileSize, fileSize + chunkSize) = 20 bytes.
        $budget = $session->byteBudget(10, 10737418240);
        $this->assertSame(20, $budget);

        // Both writers passed their own outside-the-lock check on uploadedBytes = 0.
        $repository->updateChunkStatus($session->sessionId->value, 0, 'completed', 15, $budget);

        $this->expectException(UploadBudgetExceededException::class);
        $repository->updateChunkStatus($session->sessionId->value, 1, 'completed', 15, $budget);
    }

    /**
     * REGRESSION 5b: the counter is left untouched when the budget refuses the write.
     *
     * A guard that throws after mutating would be worse than none: the session would
     * carry bytes for a chunk that was never accepted.
     */
    public function test_a_refused_write_leaves_the_session_exactly_as_it_was(): void
    {
        Config::set('stateful-chunking-upload.chunk_size_bytes', 10);

        /** @var StateRepositoryInterface $repository */
        $repository = app(StateRepositoryInterface::class);

        $session = new ChunkSession(
            sessionId: SessionId::generate(),
            fileName: 'tight.bin',
            fileSize: 10,
            totalChunks: 3,
            totalHash: ChunkHash::fromString(hash('sha256', 'tight')),
            fingerprint: 'tight-budget-2',
            status: SessionStatus::PENDING,
            ownerId: SessionOwner::fromString('user:99')
        );
        $repository->saveSession($session);

        $repository->updateChunkStatus($session->sessionId->value, 0, 'completed', 15, 20);

        try {
            $repository->updateChunkStatus($session->sessionId->value, 1, 'completed', 15, 20);
            $this->fail('The second write should have been refused.');
        } catch (UploadBudgetExceededException) {
            // expected
        }

        $reloaded = $repository->getSession($session->sessionId->value);
        $this->assertNotNull($reloaded);
        $this->assertSame(15, $reloaded->uploadedBytes);
        $this->assertSame('pending', $reloaded->chunksMap[1]);
    }

    /**
     * REGRESSION 5c: omitting the budget keeps the previous behaviour.
     *
     * The parameter is optional so an implementation written against the old signature
     * still satisfies the contract. This pins that the omission is what removes the
     * protection — which is also why passing it is not optional for this package's own
     * Action.
     */
    public function test_without_a_budget_the_write_is_unguarded_as_before(): void
    {
        /** @var StateRepositoryInterface $repository */
        $repository = app(StateRepositoryInterface::class);

        $session = new ChunkSession(
            sessionId: SessionId::generate(),
            fileName: 'tight.bin',
            fileSize: 10,
            totalChunks: 3,
            totalHash: ChunkHash::fromString(hash('sha256', 'tight')),
            fingerprint: 'tight-budget-3',
            status: SessionStatus::PENDING,
            ownerId: SessionOwner::fromString('user:99')
        );
        $repository->saveSession($session);

        $repository->updateChunkStatus($session->sessionId->value, 0, 'completed', 15);
        $repository->updateChunkStatus($session->sessionId->value, 1, 'completed', 15);

        $reloaded = $repository->getSession($session->sessionId->value);
        $this->assertNotNull($reloaded);
        $this->assertSame(30, $reloaded->uploadedBytes, 'Overshoot is the old behaviour the budget parameter exists to stop');
    }

    // ------------------------------------------------------------------ AF-008

    /**
     * REGRESSION 6: a raw-body chunk made entirely of NUL bytes is a real chunk.
     */
    public function test_a_raw_body_chunk_of_nul_bytes_is_accepted(): void
    {
        $nulChunk = str_repeat("\0", 512);
        $tail = 'TAIL';
        $full = $nulChunk.$tail;

        $initiate = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'sparse.img',
            'file_size' => strlen($full),
            'total_chunks' => 2,
            'total_hash' => hash('sha256', $full),
            'fingerprint' => 'sparse-image',
        ]);
        $initiate->assertStatus(201);
        $sessionId = (string) $initiate->json('data.session_id');

        // Raw request body, not multipart — the branch trim() used to reject.
        $response = $this->call(
            'POST',
            '/api/chunks/upload?session_id='.$sessionId.'&chunk_index=0&chunk_hash='.hash('sha256', $nulChunk),
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/octet-stream'],
            $nulChunk
        );

        $response->assertStatus(200);
        Storage::disk('local')->assertExists(sprintf('chunks_temp/%s/chunk_0.tmp', $sessionId));
        $this->assertSame($nulChunk, Storage::disk('local')->get(sprintf('chunks_temp/%s/chunk_0.tmp', $sessionId)));
    }

    /**
     * REGRESSION 6b: a genuinely empty raw body is still refused. The fix narrowed the
     * guard, it did not remove it.
     */
    public function test_a_truly_empty_raw_body_is_still_refused(): void
    {
        $body = 'SOMETHING';
        $initiate = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'empty-test.bin',
            'file_size' => strlen($body),
            'total_chunks' => 1,
            'total_hash' => hash('sha256', $body),
            'fingerprint' => 'empty-body',
        ]);
        $sessionId = (string) $initiate->json('data.session_id');

        $response = $this->call(
            'POST',
            '/api/chunks/upload?session_id='.$sessionId.'&chunk_index=0&chunk_hash='.hash('sha256', ''),
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/octet-stream'],
            ''
        );

        $response->assertStatus(422);
    }
}
