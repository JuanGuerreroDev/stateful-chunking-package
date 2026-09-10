<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Tests\Unit\Architecture;

use Juanoecr\StatefulChunkingUpload\Tests\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Executable form of the dependency rule in docs/architecture/README.md.
 *
 * The rule used to live only in that document, which is a weak place for a rule to
 * live: when the code contradicts prose, the cheapest resolution is to amend the
 * prose, and after one or two of those the document has stopped constraining
 * anything. Encoding it here inverts that — a new coupling fails CI, and relaxing
 * the rule becomes a visible edit to an allowlist rather than a quiet rewording.
 *
 * Two lists carry the couplings that already exist, and the difference between them
 * is the whole point:
 *
 *  - ALLOWED_FRAMEWORK_IMPORTS — someone chose this, for a reason that is written
 *    down in the architecture README.
 *  - RECORDED_DEBT — nobody chose this. It is a leak we have not paid off yet, and
 *    it is kept in a separate list so it stays uncomfortable and countable.
 */
class LayerDependencyRuleTest extends TestCase
{
    /** Layers that must not know about the framework or about Infrastructure. */
    private const INNER_PATHS = [
        'src/Core',
        'src/Modules/Chunking/Domain',
        'src/Modules/Chunking/Application',
    ];

    /**
     * Framework imports the inner layers may make. Each is justified in the
     * "Named pragmatic exceptions" table of docs/architecture/README.md.
     *
     * @var array<string, string>
     */
    private const ALLOWED_FRAMEWORK_IMPORTS = [
        'Illuminate\Foundation\Events\Dispatchable' => 'domain events are consumed by the host app',
        'Illuminate\Queue\SerializesModels' => 'domain events are consumed by the host app',
        'Illuminate\Contracts\Support\Responsable' => 'ChunkingException renders itself',
        'Illuminate\Http\JsonResponse' => 'ChunkingException renders itself',
        'Illuminate\Http\Request' => 'ChunkingException renders itself',
        'Illuminate\Support\Facades\Log' => 'ChunkingException audits itself',
        'Illuminate\Support\Facades\Crypt' => 'upload token is authenticated encryption under APP_KEY',
        'Illuminate\Support\Str' => 'UUID generation in SessionId',
    ];

    /**
     * Known violations, listed per file so they can only shrink. Do not move an entry
     * up into ALLOWED_FRAMEWORK_IMPORTS to make this test pass — that converts a leak
     * into a documented allowance, which is precisely what the rule exists to stop.
     *
     * @var array<string, list<string>>
     */
    private const RECORDED_DEBT = [
        'src/Modules/Chunking/Application/DTOs/StagedFileDTO.php' => [
            'Illuminate\Filesystem\FilesystemAdapter',
            'Illuminate\Support\Facades\Storage',
        ],
        'src/Core/Services/StatefulChunkingService.php' => [
            'Juanoecr\StatefulChunkingUpload\Modules\Chunking\Application\DTOs\StagedFileDTO',
        ],
    ];

    public function test_inner_layers_never_depend_on_infrastructure(): void
    {
        $violations = [];

        foreach ($this->innerLayerImports() as $relativePath => $imports) {
            foreach ($imports as $import) {
                if (str_contains($import, '\\Infrastructure\\')) {
                    $violations[] = sprintf('%s imports %s', $relativePath, $import);
                }
            }
        }

        $this->assertSame([], $violations, sprintf(
            "Domain, Application and Core must never reference Infrastructure.\n%s",
            implode("\n", $violations)
        ));
    }

    public function test_framework_imports_in_inner_layers_are_declared(): void
    {
        $undeclared = [];

        foreach ($this->innerLayerImports() as $relativePath => $imports) {
            foreach ($imports as $import) {
                if (! str_starts_with($import, 'Illuminate\\')) {
                    continue;
                }

                if (array_key_exists($import, self::ALLOWED_FRAMEWORK_IMPORTS)) {
                    continue;
                }

                if (in_array($import, self::RECORDED_DEBT[$relativePath] ?? [], true)) {
                    continue;
                }

                $undeclared[] = sprintf('%s imports %s', $relativePath, $import);
            }
        }

        $this->assertSame([], $undeclared, sprintf(
            "New framework coupling in an inner layer.\n%s\n\n".
            'Either remove it, or — if it is a deliberate decision — add it to '.
            'ALLOWED_FRAMEWORK_IMPORTS here AND to the exceptions table in '.
            'docs/architecture/README.md, so the reason survives the commit.',
            implode("\n", $undeclared)
        ));
    }

    public function test_core_does_not_depend_on_application(): void
    {
        $violations = [];

        foreach ($this->innerLayerImports() as $relativePath => $imports) {
            if (! str_starts_with($relativePath, 'src/Core/')) {
                continue;
            }

            foreach ($imports as $import) {
                if (! str_contains($import, '\\Application\\')) {
                    continue;
                }

                if (in_array($import, self::RECORDED_DEBT[$relativePath] ?? [], true)) {
                    continue;
                }

                $violations[] = sprintf('%s imports %s', $relativePath, $import);
            }
        }

        $this->assertSame([], $violations, sprintf(
            "Core is the innermost layer and must not depend on Application.\n%s",
            implode("\n", $violations)
        ));
    }

    /**
     * Debt entries are claims about the code, so they rot like any other claim. When a
     * violation is finally fixed, this fails and asks for the entry to be deleted —
     * which is how the list is kept honest instead of merely long.
     */
    public function test_recorded_debt_still_describes_the_code(): void
    {
        $stale = [];
        $imports = $this->innerLayerImports();

        foreach (self::RECORDED_DEBT as $relativePath => $debtImports) {
            foreach ($debtImports as $debtImport) {
                if (! in_array($debtImport, $imports[$relativePath] ?? [], true)) {
                    $stale[] = sprintf('%s no longer imports %s', $relativePath, $debtImport);
                }
            }
        }

        $this->assertSame([], $stale, sprintf(
            "Recorded debt has been paid off — delete these entries.\n%s\n\n".
            'Also remove the matching item from "Observed structural debt" in '.
            'docs/architecture/README.md.',
            implode("\n", $stale)
        ));
    }

    /**
     * @return array<string, list<string>> relative file path => imported FQCNs
     */
    private function innerLayerImports(): array
    {
        $root = dirname(__DIR__, 3);
        $imports = [];

        foreach (self::INNER_PATHS as $innerPath) {
            $absolute = $root.'/'.$innerPath;
            if (! is_dir($absolute)) {
                continue;
            }

            /** @var RecursiveIteratorIterator<RecursiveDirectoryIterator> $files */
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute));

            foreach ($files as $file) {
                if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());
                $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

                preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+\w+)?\s*;/m', $contents, $matches);

                /** @var list<string> $matched */
                $matched = $matches[1];
                $imports[$relativePath] = $matched;
            }
        }

        return $imports;
    }
}
