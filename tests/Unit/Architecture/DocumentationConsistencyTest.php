<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Tests\Unit\Architecture;

use Illuminate\Foundation\Http\FormRequest;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Exceptions\ChunkIndexOutOfBoundsException;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Exceptions\ChunkIntegrityException;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Exceptions\SessionNotFoundException;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Exceptions\SessionNotReadyException;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Exceptions\StorageFailureException;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Exceptions\UnauthorizedSessionAccessException;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Exceptions\UploadBudgetExceededException;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Infrastructure\Http\Requests\CompleteChunkRequest;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Infrastructure\Http\Requests\InitiateChunkRequest;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Infrastructure\Http\Requests\UploadChunkRequest;
use Juanoecr\StatefulChunkingUpload\Tests\TestCase;

/**
 * Keeps the published documentation honest by checking it against the code.
 *
 * The reason this file exists is empirical. Four rounds of hardening produced documents
 * asserting controls the code did not implement — `require_auth` covering five endpoints
 * when it covered two, a garbage collector that collected nothing, rate limits
 * partitioned per user by an expression that was always false. In the review of those
 * documents, only the claims that had been checked by a program survived; every claim
 * verified "by reading" turned out to be wrong somewhere.
 *
 * So the load-bearing claims are checked here. This is not a style linter: each test
 * pins a statement a reader would act on.
 *
 * @see LayerDependencyRuleTest for the same treatment applied to the dependency rule
 */
class DocumentationConsistencyTest extends TestCase
{
    /**
     * Documents that ship with the package. `docs/audits/` and `docs/proposals/` are
     * gitignored working material and deliberately excluded.
     *
     * @var array<int, string>
     */
    private const TRACKED_DOCS = [
        'README.md',
        'CHANGELOG.md',
        'docs/architecture',
        'docs/decisions',
        'docs/security',
    ];

    private function packageRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return array<string, string> relative path => contents
     */
    private function trackedMarkdown(): array
    {
        $files = [];

        foreach (self::TRACKED_DOCS as $entry) {
            $absolute = $this->packageRoot().DIRECTORY_SEPARATOR.$entry;

            if (is_file($absolute)) {
                $files[$entry] = (string) file_get_contents($absolute);

                continue;
            }

            $found = glob($absolute.DIRECTORY_SEPARATOR.'*.md') ?: [];
            foreach ($found as $path) {
                $files[$entry.'/'.basename($path)] = (string) file_get_contents($path);
            }
        }

        $this->assertNotEmpty($files, 'No tracked documentation was found to check.');

        return $files;
    }

    /**
     * Line numbers in prose rot the moment anyone edits the file they point at, and they
     * rot silently: nothing fails, the reference just starts naming a different line.
     *
     * This was not hypothetical. Ten references of the form `SomeClass:42` were written
     * into `docs/architecture/` and every one of them had drifted within days — several
     * pointed at blank lines and closing braces. Reference a symbol instead: a class, a
     * method, a validation rule. Those are stable, and greppable, which is the property
     * a reader actually needs.
     */
    public function test_documentation_carries_no_line_number_references(): void
    {
        $offenders = [];

        foreach ($this->trackedMarkdown() as $relative => $contents) {
            if (preg_match_all('/`([A-Z][A-Za-z0-9_]*(?:\.php)?):(\d+)(?:-\d+)?`/', $contents, $matches)) {
                foreach ($matches[0] as $match) {
                    $offenders[] = $relative.' → '.$match;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Documentation must reference symbols, not line numbers, because line numbers rot silently:\n"
            .implode("\n", $offenders)
        );
    }

    /**
     * Every relative link in the published documentation must resolve to a file that
     * ships with the package.
     *
     * Two ways this breaks, and both happened here. A path can be renamed while the
     * links to it are not, and a document can point at working material that is
     * deliberately gitignored: the architecture docs referenced the private security
     * review by its path, which reads as a file the reader can open and is absent from
     * every clone. Provenance is worth recording; a path that cannot resolve is not the
     * way to record it.
     */
    public function test_every_relative_documentation_link_resolves(): void
    {
        $broken = [];
        $examined = 0;

        foreach ($this->trackedMarkdown() as $relative => $contents) {
            $directory = dirname($this->packageRoot().DIRECTORY_SEPARATOR.$relative);

            preg_match_all('/\]\(([^)\s]+)\)/', $contents, $matches);

            foreach ($matches[1] as $target) {
                // External links and pure anchors are out of scope.
                if (preg_match('/^(https?:|mailto:|\#)/', $target)) {
                    continue;
                }

                $path = strtok($target, '#');

                if ($path === false || $path === '') {
                    continue;
                }

                $examined++;

                if (! file_exists($directory.DIRECTORY_SEPARATOR.$path)) {
                    $broken[] = $relative.' → '.$target;
                }
            }
        }

        $this->assertSame(
            [],
            $broken,
            'These documentation links do not resolve to a file that ships with the package: '
            .implode(', ', $broken)
        );

        // Without this the assertion above passes vacuously the day the link syntax
        // changes or the extraction regex stops matching, which is the failure mode of
        // every check that only ever asserts an empty result.
        $this->assertGreaterThan(
            10,
            $examined,
            'Too few relative links were examined for this check to mean anything.'
        );
    }

    /**
     * Every input the package validates must be named in the trust table.
     *
     * The table's whole purpose is to make "where is this value validated, normalised
     * and first trusted" answerable at a glance; an input missing from it is an input
     * whose trust point nobody stated. AF-001 was exactly that kind of gap.
     */
    public function test_every_validated_input_is_named_in_the_trust_table(): void
    {
        $table = (string) file_get_contents(
            $this->packageRoot().'/docs/architecture/data-transformations.md'
        );

        $inputs = [];
        foreach ([InitiateChunkRequest::class, UploadChunkRequest::class, CompleteChunkRequest::class] as $requestClass) {
            /** @var FormRequest $request */
            $request = new $requestClass;
            /** @var array<string, mixed> $rules */
            $rules = $request->rules();
            foreach (array_keys($rules) as $field) {
                $inputs[(string) $field] = true;
            }
        }

        $missing = [];
        foreach (array_keys($inputs) as $field) {
            if (! str_contains($table, '`'.$field.'`')) {
                $missing[] = $field;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'These validated inputs are absent from docs/architecture/data-transformations.md: '
            .implode(', ', $missing)
        );
    }

    /**
     * The exception-to-status table in the request lifecycle document is a contract
     * readers integrate against, so it must match what the classes actually declare.
     *
     * Three of its rows were wrong when first written: SessionNotReady said 422 twice
     * and ChunkIndexOutOfBounds said 400. All three were "verified by reading".
     */
    public function test_the_exception_status_table_matches_the_exception_classes(): void
    {
        $document = (string) file_get_contents(
            $this->packageRoot().'/docs/architecture/request-lifecycle.md'
        );

        $expected = [
            SessionNotFoundException::class => 404,
            UnauthorizedSessionAccessException::class => 403,
            SessionNotReadyException::class => 409,
            ChunkIntegrityException::class => 422,
            ChunkIndexOutOfBoundsException::class => 422,
            UploadBudgetExceededException::class => 413,
            StorageFailureException::class => 500,
        ];

        foreach ($expected as $class => $documented) {
            $shortName = (new \ReflectionClass($class))->getShortName();

            // What the class declares, read from the instance rather than the source.
            $actual = (new $class('probe'))->statusCode();
            $this->assertSame(
                $documented,
                $actual,
                sprintf('%s declares %d, but this test expected %d.', $shortName, $actual, $documented)
            );

            // And what the document says about it.
            $this->assertMatchesRegularExpression(
                '/\|\s*`'.preg_quote($shortName, '/').'`\s*\|\s*'.$actual.'\s*\|/',
                $document,
                sprintf('request-lifecycle.md must list %s as %d.', $shortName, $actual)
            );
        }
    }

    /**
     * The README's endpoint table publishes the shipped rate limits. An operator sizes
     * their client's concurrency from those numbers, so a stale one is a promise broken
     * at runtime rather than a typo.
     */
    public function test_the_readme_rate_limit_table_matches_the_shipped_defaults(): void
    {
        $readme = (string) file_get_contents($this->packageRoot().'/README.md');
        $config = require $this->packageRoot().'/config/stateful-chunking-upload.php';

        $endpoints = [
            'initiate' => '/api/chunks/initiate',
            'upload' => '/api/chunks/upload',
            'status' => '/api/chunks/status/{sessionId}',
            'complete' => '/api/chunks/complete',
            'cancel' => '/api/chunks/cancel/{sessionId}',
        ];

        foreach ($endpoints as $key => $path) {
            $limit = $config['rate_limits'][$key];
            $this->assertIsInt($limit);

            $this->assertMatchesRegularExpression(
                '/`'.preg_quote($path, '/').'`.*`'.$limit.' req \/ min`/',
                $readme,
                sprintf('README must publish %s as %d req/min.', $path, $limit)
            );
        }
    }

    /**
     * Every ADR the index lists must exist, and every ADR file must be listed.
     *
     * An index that drifts from the directory is how a decision becomes invisible while
     * still governing the code.
     */
    public function test_the_adr_index_and_the_adr_directory_agree(): void
    {
        $directory = $this->packageRoot().'/docs/decisions';
        $index = (string) file_get_contents($directory.'/README.md');

        $files = array_map(
            'basename',
            array_filter(
                glob($directory.'/*.md') ?: [],
                static fn (string $path): bool => basename($path) !== 'README.md'
            )
        );

        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $this->assertStringContainsString(
                '('.$file.')',
                $index,
                sprintf('docs/decisions/README.md does not link %s.', $file)
            );
        }

        // And the reverse: nothing linked that is not there.
        preg_match_all('/\((\d{4}-[a-z0-9-]+\.md)\)/', $index, $linked);
        foreach (array_unique($linked[1]) as $link) {
            $this->assertContains(
                $link,
                $files,
                sprintf('docs/decisions/README.md links %s, which does not exist.', $link)
            );
        }
    }

    /**
     * An accepted ADR describes code that exists. This checks the status recorded in the
     * front matter against the artefacts each decision says it produced, so an ADR
     * cannot sit at `accepted` while its implementation is absent.
     */
    public function test_accepted_adrs_have_their_implementation_in_place(): void
    {
        $artefacts = [
            '0003-normalise-identity-at-the-adapter-boundary.md' => [
                'src/Core/ValueObjects/SessionOwner.php',
                'src/Modules/Chunking/Infrastructure/Http/Requests/CompleteChunkRequest.php',
                'docs/architecture/data-transformations.md',
            ],
            '0004-staged-chunk-lifecycle-and-garbage-collection.md' => [
                'src/Core/Contracts/PrunableChunkStorageInterface.php',
                'src/Modules/Chunking/Domain/Events/ChunkSessionExpired.php',
                'src/Modules/Chunking/Infrastructure/Listeners/PurgeExpiredSessionChunks.php',
            ],
        ];

        foreach ($artefacts as $adr => $paths) {
            $front = (string) file_get_contents($this->packageRoot().'/docs/decisions/'.$adr);

            if (! str_contains($front, "status: 'accepted'")) {
                continue;
            }

            foreach ($paths as $path) {
                $this->assertFileExists(
                    $this->packageRoot().'/'.$path,
                    sprintf('%s is accepted but %s is missing.', $adr, $path)
                );
            }
        }
    }
}
