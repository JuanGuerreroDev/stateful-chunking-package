<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Tests\Feature\Security;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunking\Tests\TestCase;

/**
 * VULN-02 REGRESSION TEST: Comprehensive Extension Validation & Webshell Prevention
 *
 * Security Invariants:
 * 1. Multi-segment / double extension evasion (e.g. shell.php.jpg) MUST be detected and rejected.
 * 2. Dot-files (e.g. .htaccess, .env) MUST be rejected with HTTP 422.
 * 3. Known executable & server-side script variants (.pht, .php5..8, .asp, .shtml, .inc, etc.) MUST be blocked.
 * 4. Trailing dots and trailing spaces (which normalize on Windows) MUST be rejected with HTTP 422.
 * 5. Safe legitimate files (.pdf, .jpg, .zip, .csv) MUST continue to be accepted with HTTP 201.
 * 6. Whitelisting (when configured) MUST strictly enforce allowed extensions.
 */
class Vuln02ExtensionValidationRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Config::set('stateful-chunking.rate_limits.initiate', 1000);
    }

    /**
     * Helper to initiate an upload session with a given filename.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function attemptInitiate(string $fileName): \Illuminate\Testing\TestResponse
    {
        \Illuminate\Support\Facades\RateLimiter::clear('stateful-chunking-initiate');

        return $this->postJson('/api/chunks/initiate', [
            'file_name'    => $fileName,
            'file_size'    => 1024,
            'total_chunks' => 1,
            'total_hash'   => hash('sha256', 'dummy_content_for_regression_test'),
            'fingerprint'  => 'reg_fp_' . uniqid(),
        ]);
    }

    /**
     * REGRESSION 1: Double extension evasion must be blocked.
     */
    public function test_rejects_double_extension_attacks(): void
    {
        $payloads = [
            'shell.php.jpg',
            'backdoor.phar.png',
            'script.phtml.gif',
            'exploit.pht.txt',
            'trojan.sh.pdf',
            'service.exe.zip',
            'runner.bat.mp4',
            'shell.asp.csv',
        ];

        foreach ($payloads as $fileName) {
            $response = $this->attemptInitiate($fileName);
            $this->assertEquals(
                422,
                $response->status(),
                "Double extension payload [{$fileName}] should have been blocked with 422, got {$response->status()}"
            );
            $response->assertJsonValidationErrors('file_name');
        }
    }

    /**
     * REGRESSION 2: Dot-files must be blocked.
     */
    public function test_rejects_dot_files(): void
    {
        $dotFiles = [
            '.htaccess',
            '.env',
            '.gitignore',
            '.user.ini',
            '.htpasswd',
        ];

        foreach ($dotFiles as $fileName) {
            $response = $this->attemptInitiate($fileName);
            $this->assertEquals(
                422,
                $response->status(),
                "Dot-file payload [{$fileName}] should have been blocked with 422, got {$response->status()}"
            );
            $response->assertJsonValidationErrors('file_name');
        }
    }

    /**
     * REGRESSION 3: Server-side script & executable extensions omitted from baseline blacklist must be blocked.
     */
    public function test_rejects_alternative_executable_extensions(): void
    {
        $dangerousExtensions = [
            'shell.pht',
            'shell.php5',
            'shell.php7',
            'shell.php8',
            'shell.phps',
            'shell.inc',
            'page.shtml',
            'page.shtm',
            'app.asp',
            'app.aspx',
            'app.cer',
            'app.asa',
            'app.cfm',
        ];

        foreach ($dangerousExtensions as $fileName) {
            $response = $this->attemptInitiate($fileName);
            $this->assertEquals(
                422,
                $response->status(),
                "Dangerous extension payload [{$fileName}] should have been blocked with 422, got {$response->status()}"
            );
            $response->assertJsonValidationErrors('file_name');
        }
    }

    /**
     * REGRESSION 4: Trailing dots and spaces must be blocked.
     */
    public function test_rejects_trailing_dot_and_space_names(): void
    {
        $trailingNames = [
            'shell.php.',
            'backdoor.phar.',
            'script.exe.',
        ];

        foreach ($trailingNames as $fileName) {
            $response = $this->attemptInitiate($fileName);
            $this->assertEquals(
                422,
                $response->status(),
                "Trailing dot payload [{$fileName}] should have been blocked with 422, got {$response->status()}"
            );
            $response->assertJsonValidationErrors('file_name');
        }
    }

    /**
     * REGRESSION 5: Legitimate files with safe extensions must be accepted without issues.
     */
    public function test_accepts_legitimate_safe_extensions(): void
    {
        $safeFiles = [
            'annual_report.pdf',
            'profile_picture.jpg',
            'graphic.png',
            'backup_archive.zip',
            'data_export.csv',
            'readme.txt',
            'video_clip.mp4',
        ];

        foreach ($safeFiles as $fileName) {
            $response = $this->attemptInitiate($fileName);
            $this->assertEquals(
                201,
                $response->status(),
                "Legitimate file [{$fileName}] should have been accepted with 201, got {$response->status()}"
            );
        }
    }

    /**
     * REGRESSION 6: Whitelist configuration is enforced when defined.
     */
    public function test_enforces_whitelist_when_configured(): void
    {
        // Configure strict whitelist: only PDF and PNG allowed
        Config::set('stateful-chunking.allowed_extensions', ['pdf', 'png']);

        // Safe within whitelist -> Accepted (201)
        $validPdf = $this->attemptInitiate('document.pdf');
        $validPdf->assertStatus(201);

        $validPng = $this->attemptInitiate('chart.png');
        $validPng->assertStatus(201);

        // Safe extension but NOT in whitelist -> Blocked (422)
        $blockedZip = $this->attemptInitiate('data.zip');
        $blockedZip->assertStatus(422);
        $blockedZip->assertJsonValidationErrors('file_name');

        $blockedJpg = $this->attemptInitiate('photo.jpg');
        $blockedJpg->assertStatus(422);
        $blockedJpg->assertJsonValidationErrors('file_name');
    }
}
