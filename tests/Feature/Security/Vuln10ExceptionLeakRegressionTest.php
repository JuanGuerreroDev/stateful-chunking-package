<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Tests\Feature\Security;

use Juanoecr\StatefulChunking\Core\Contracts\StateRepositoryInterface;
use Juanoecr\StatefulChunking\Tests\TestCase;
use RuntimeException;

/**
 * VULN-10 REGRESSION TEST: Exception Message Leak & Information Disclosure Prevention (CWE-209)
 *
 * Security Invariants:
 * 1. An internal exception/throwable in /initiate MUST NOT leak internal file paths,
 *    stack traces, or exception class names to the HTTP client. It must return a sanitized error response.
 * 2. An internal exception/throwable in /upload (including repository resolution) MUST NOT leak internal paths.
 * 3. An internal exception/throwable in /complete (including repository resolution) MUST NOT leak internal paths.
 * 4. An internal exception/throwable in /cancel MUST NOT leak internal paths.
 */
class Vuln10ExceptionLeakRegressionTest extends TestCase
{
    private const VALID_UUID = 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d';

    private function validInitiatePayload(): array
    {
        return [
            'file_name'    => 'safe_file.txt',
            'file_size'    => 1024,
            'total_chunks' => 1,
            'total_hash'   => str_repeat('a', 64),
            'fingerprint'  => 'fp_' . uniqid(),
        ];
    }

    /**
     * REGRESSION 1: /initiate catches internal throwables and never leaks server file paths.
     */
    public function test_initiate_catches_internal_exception_and_does_not_leak_paths(): void
    {
        $sensitivePath = '/var/www/html/storage/internal_error_trace.php';

        $repoMock = $this->createMock(StateRepositoryInterface::class);
        $repoMock->method('saveSession')
            ->willThrowException(new RuntimeException("Critical disk failure at {$sensitivePath}"));
        $this->app->instance(StateRepositoryInterface::class, $repoMock);

        $response = $this->postJson('/api/chunks/initiate', $this->validInitiatePayload());

        $this->assertContains($response->status(), [400, 500], "Expected 400 or 500 status on internal error, got {$response->status()}");
        $json = $response->json();
        $message = (string) ($json['message'] ?? '');

        $this->assertStringNotContainsString('/var/www', $message);
        $this->assertStringNotContainsString('RuntimeException', $message);
        $this->assertStringNotContainsString('internal_error_trace', $message);
    }

    /**
     * REGRESSION 2: /upload catches repository exceptions before the inner try-block and does not leak paths.
     */
    public function test_upload_catches_pre_action_repository_exceptions(): void
    {
        $sensitivePath = '/etc/ssl/certs/database_credentials.key';

        $repoMock = $this->createMock(StateRepositoryInterface::class);
        $repoMock->method('getSession')
            ->willThrowException(new RuntimeException("Connection rejected reading {$sensitivePath}"));
        $this->app->instance(StateRepositoryInterface::class, $repoMock);

        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('chunk_0.tmp', 'dummy chunk content');
        $response = $this->call(
            'POST',
            '/api/chunks/upload',
            [
                'session_id'  => self::VALID_UUID,
                'chunk_index' => 0,
                'chunk_hash'  => hash('sha256', 'dummy chunk content'),
            ],
            [],
            ['file' => $file],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $this->assertContains($response->status(), [400, 500], "Expected 400 or 500 status on internal error, got {$response->status()}");
        $json = $response->json();
        $message = (string) ($json['message'] ?? '');

        $this->assertStringNotContainsString('/etc/ssl', $message);
        $this->assertStringNotContainsString('RuntimeException', $message);
        $this->assertStringNotContainsString('database_credentials', $message);
    }

    /**
     * REGRESSION 3: /complete catches repository exceptions and does not leak paths.
     */
    public function test_complete_catches_pre_action_repository_exceptions(): void
    {
        $sensitivePath = '/opt/app/secure_storage/encryption_failure.log';

        $repoMock = $this->createMock(StateRepositoryInterface::class);
        $repoMock->method('getSession')
            ->willThrowException(new RuntimeException("Fatal error reading {$sensitivePath}"));
        $this->app->instance(StateRepositoryInterface::class, $repoMock);

        $response = $this->postJson('/api/chunks/complete', [
            'session_id' => self::VALID_UUID,
        ]);

        $this->assertContains($response->status(), [400, 500], "Expected 400 or 500 status on internal error, got {$response->status()}");
        $json = $response->json();
        $message = (string) ($json['message'] ?? '');

        $this->assertStringNotContainsString('/opt/app', $message);
        $this->assertStringNotContainsString('RuntimeException', $message);
        $this->assertStringNotContainsString('encryption_failure', $message);
    }

    /**
     * REGRESSION 4: /cancel catches exceptions and does not leak paths.
     */
    public function test_cancel_catches_internal_exceptions(): void
    {
        $sensitivePath = '/var/log/nginx/secret_cluster_node.conf';

        $repoMock = $this->createMock(StateRepositoryInterface::class);
        $repoMock->method('getSession')
            ->willThrowException(new RuntimeException("Cluster communication lost at {$sensitivePath}"));
        $this->app->instance(StateRepositoryInterface::class, $repoMock);

        $response = $this->deleteJson('/api/chunks/cancel/' . self::VALID_UUID);

        $this->assertContains($response->status(), [400, 500], "Expected 400 or 500 status on internal error, got {$response->status()}");
        $json = $response->json();
        $message = (string) ($json['message'] ?? '');

        $this->assertStringNotContainsString('/var/log', $message);
        $this->assertStringNotContainsString('RuntimeException', $message);
        $this->assertStringNotContainsString('secret_cluster_node', $message);
    }
}
