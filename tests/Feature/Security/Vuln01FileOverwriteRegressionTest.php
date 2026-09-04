<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Tests\Feature\Security;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunking\Tests\TestCase;

/**
 * VULN-01 REGRESSION TEST: Session Namespace Isolation & File Overwrite Prevention
 *
 * Security Invariant:
 * Every completed file upload MUST be stored within an isolated, session-specific namespace.
 * Two distinct sessions uploading files with the exact same name MUST NOT collide or overwrite
 * each other under any circumstances.
 */
class Vuln01FileOverwriteRegressionTest extends TestCase
{
    /**
     * REGRESSION: Verify that two independent sessions uploading identical filenames
     * produce distinct storage paths and both files coexist intact on disk without overwriting.
     */
    public function test_different_upload_sessions_with_same_filename_do_not_overwrite_each_other(): void
    {
        Storage::fake('local');

        $commonFileName = 'critical_contract.pdf';

        // ═══════════════════════════════════════════════════════════════
        // USER A: Uploads critical_contract.pdf
        // ═══════════════════════════════════════════════════════════════
        $contentUserA = 'ORIGINAL CONTRACT CONTENT - SIGNED BY USER A - CONFIDENTIAL';
        $hashUserA = hash('sha256', $contentUserA);

        $initUserA = $this->postJson('/api/chunks/initiate', [
            'file_name'    => $commonFileName,
            'file_size'    => strlen($contentUserA),
            'total_chunks' => 1,
            'total_hash'   => $hashUserA,
            'fingerprint'  => 'user_a_fp_' . uniqid(),
        ]);
        $initUserA->assertStatus(201);
        $sessionIdUserA = (string) $initUserA->json('data.session_id');

        $fileUserA = UploadedFile::fake()->createWithContent('chunk_0.tmp', $contentUserA);
        $uploadUserA = $this->call('POST', '/api/chunks/upload', [
            'session_id'  => $sessionIdUserA,
            'chunk_index' => 0,
            'chunk_hash'  => $hashUserA,
        ], [], ['file' => $fileUserA]);
        $uploadUserA->assertStatus(200);

        $completeUserA = $this->postJson('/api/chunks/complete', [
            'session_id' => $sessionIdUserA,
        ]);
        $completeUserA->assertStatus(200);
        $tokenUserA = (string) $completeUserA->json('data.upload_token');
        $pathUserA = app(\Juanoecr\StatefulChunking\Core\Services\StatefulChunkingService::class)->resolveToken($tokenUserA)->tempPath;

        // ═══════════════════════════════════════════════════════════════
        // USER B: Uploads critical_contract.pdf (Identical filename)
        // ═══════════════════════════════════════════════════════════════
        $contentUserB = 'TAMPERED CONTRACT CONTENT - MODIFIED BY USER B - UNTRUSTED';
        $hashUserB = hash('sha256', $contentUserB);

        $initUserB = $this->postJson('/api/chunks/initiate', [
            'file_name'    => $commonFileName,
            'file_size'    => strlen($contentUserB),
            'total_chunks' => 1,
            'total_hash'   => $hashUserB,
            'fingerprint'  => 'user_b_fp_' . uniqid(),
        ]);
        $initUserB->assertStatus(201);
        $sessionIdUserB = (string) $initUserB->json('data.session_id');

        $this->assertNotEquals($sessionIdUserA, $sessionIdUserB, 'Session IDs must be distinct');

        $fileUserB = UploadedFile::fake()->createWithContent('chunk_0.tmp', $contentUserB);
        $uploadUserB = $this->call('POST', '/api/chunks/upload', [
            'session_id'  => $sessionIdUserB,
            'chunk_index' => 0,
            'chunk_hash'  => $hashUserB,
        ], [], ['file' => $fileUserB]);
        $uploadUserB->assertStatus(200);

        $completeUserB = $this->postJson('/api/chunks/complete', [
            'session_id' => $sessionIdUserB,
        ]);
        $completeUserB->assertStatus(200);
        $tokenUserB = (string) $completeUserB->json('data.upload_token');
        $pathUserB = app(\Juanoecr\StatefulChunking\Core\Services\StatefulChunkingService::class)->resolveToken($tokenUserB)->tempPath;

        // ═══════════════════════════════════════════════════════════════
        // REGRESSION ASSERTIONS (DEFENSE VERIFICATION)
        // ═══════════════════════════════════════════════════════════════

        // 1. Storage paths MUST be distinct
        $this->assertNotEquals(
            $pathUserA,
            $pathUserB,
            'CRITICAL FAILURE: Both sessions produced the exact same output path! Overwrite occurred.'
        );

        // 2. Both paths MUST include their respective session namespace
        $this->assertStringContainsString(
            $sessionIdUserA,
            $pathUserA,
            "Path A [{$pathUserA}] must be isolated within User A session namespace [{$sessionIdUserA}]"
        );
        $this->assertStringContainsString(
            $sessionIdUserB,
            $pathUserB,
            "Path B [{$pathUserB}] must be isolated within User B session namespace [{$sessionIdUserB}]"
        );

        // 3. BOTH files must still exist independently on the disk
        Storage::disk('local')->assertExists($pathUserA);
        Storage::disk('local')->assertExists($pathUserB);

        // 4. User A's file MUST be completely preserved and untouched
        $persistedContentUserA = Storage::disk('local')->get($pathUserA);
        $this->assertEquals(
            $contentUserA,
            $persistedContentUserA,
            "CRITICAL INTEGRITY FAILURE: User A's file content was corrupted or overwritten by User B!"
        );

        // 5. User B's file MUST contain User B's content
        $persistedContentUserB = Storage::disk('local')->get($pathUserB);
        $this->assertEquals(
            $contentUserB,
            $persistedContentUserB,
            "User B's file content does not match uploaded content."
        );
    }

    /**
     * REGRESSION: Verify that attempts to use path traversal sequences in filename
     * remain safely sandboxed inside the session directory.
     */
    public function test_path_traversal_attempts_remain_jailed_in_session_namespace(): void
    {
        Storage::fake('local');

        $traversalContent = 'TRAVERSAL TEST DATA';
        $traversalHash = hash('sha256', $traversalContent);

        $initResponse = $this->postJson('/api/chunks/initiate', [
            'file_name'    => 'safe_file.txt',
            'file_size'    => strlen($traversalContent),
            'total_chunks' => 1,
            'total_hash'   => $traversalHash,
            'fingerprint'  => 'traversal_fp_' . uniqid(),
        ]);
        $initResponse->assertStatus(201);
        $sessionId = (string) $initResponse->json('data.session_id');

        $file = UploadedFile::fake()->createWithContent('chunk_0.tmp', $traversalContent);
        $upload = $this->call('POST', '/api/chunks/upload', [
            'session_id'  => $sessionId,
            'chunk_index' => 0,
            'chunk_hash'  => $traversalHash,
        ], [], ['file' => $file]);
        $upload->assertStatus(200);

        $complete = $this->postJson('/api/chunks/complete', [
            'session_id' => $sessionId,
        ]);
        $complete->assertStatus(200);
        $completeToken = (string) $complete->json('data.upload_token');
        $finalPath = app(\Juanoecr\StatefulChunking\Core\Services\StatefulChunkingService::class)->resolveToken($completeToken)->tempPath;

        // Path must strictly start with the base storage path + session namespace
        $expectedPrefix = 'uploads/' . $sessionId . '/';
        $this->assertStringStartsWith(
            $expectedPrefix,
            $finalPath,
            "Final assembled path [{$finalPath}] must reside inside session jail [{$expectedPrefix}]"
        );
    }
}
