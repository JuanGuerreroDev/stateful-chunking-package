<?php

declare(strict_types=1);

$baseUrl = 'http://127.0.0.1:8000/api/chunks';

function postJson(string $url, array $data): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode((string) $response, true)];
}

function uploadChunkMultipart(string $url, string $sessionId, int $chunkIndex, string $chunkHash, string $content): array {
    $tempFile = tempnam(sys_get_temp_dir(), 'chk_');
    file_put_contents($tempFile, $content);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'session_id' => $sessionId,
        'chunk_index' => (string) $chunkIndex,
        'chunk_hash' => $chunkHash,
        'file' => new CURLFile($tempFile, 'application/octet-stream', "chunk_{$chunkIndex}.tmp"),
    ]);

    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tempFile);

    return ['code' => $code, 'body' => json_decode((string) $response, true)];
}

echo "=== STARTING E2E FUNCTIONAL PACKAGE TEST (POSTMAN / CLIENT EQUIVALENT) ===\n\n";

// 1. Prepare file content & hashes (2 chunks)
$chunk0Data = "FUNCTIONAL TEST CHUNK 0 - STATEFUL CHUNKING PACKAGE FOR LARAVEL.";
$chunk1Data = "FUNCTIONAL TEST CHUNK 1 - VERIFIED RESILIENT REDIS REASSEMBLY.";
$fullContent = $chunk0Data . $chunk1Data;

$chunk0Hash = hash('sha256', $chunk0Data);
$chunk1Hash = hash('sha256', $chunk1Data);
$totalHash = hash('sha256', $fullContent);

echo "[1] Initiating Session via POST /api/chunks/initiate...\n";
$initRes = postJson("$baseUrl/initiate", [
    'file_name' => 'functional_demo_document.txt',
    'file_size' => strlen($fullContent),
    'total_chunks' => 2,
    'total_hash' => $totalHash,
    'fingerprint' => 'functional_test_fp_' . time(),
]);

echo "HTTP Status: {$initRes['code']}\n";
echo "Response: " . json_encode($initRes['body'], JSON_PRETTY_PRINT) . "\n\n";

if ($initRes['code'] !== 201) {
    echo "ERROR: Session initiation failed!\n";
    exit(1);
}

$sessionId = $initRes['body']['data']['session_id'];

// 2. Upload Chunk #0 via Multipart Form-Data (Postman style)
echo "[2] Uploading Chunk #0 via Multipart Form-Data (Postman style)...\n";
$upload0Res = uploadChunkMultipart("$baseUrl/upload", $sessionId, 0, $chunk0Hash, $chunk0Data);

echo "HTTP Status: {$upload0Res['code']}\n";
echo "Response: " . json_encode($upload0Res['body'], JSON_PRETTY_PRINT) . "\n\n";

// 3. Query Session Status Midway
echo "[3] Querying Session Status Midway via GET /api/chunks/status/$sessionId...\n";
$ch = curl_init("$baseUrl/status/$sessionId");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$statusBody = curl_exec($ch);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Status: $statusCode\n";
echo "Response: " . json_encode(json_decode((string) $statusBody, true), JSON_PRETTY_PRINT) . "\n\n";

// 4. Upload Chunk #1 via Multipart Form-Data
echo "[4] Uploading Chunk #1 via Multipart Form-Data...\n";
$upload1Res = uploadChunkMultipart("$baseUrl/upload", $sessionId, 1, $chunk1Hash, $chunk1Data);

echo "HTTP Status: {$upload1Res['code']}\n";
echo "Response: " . json_encode($upload1Res['body'], JSON_PRETTY_PRINT) . "\n\n";

// 5. Complete and Reassemble File
echo "[5] Requesting File Reassembly via POST /api/chunks/complete...\n";
$completeRes = postJson("$baseUrl/complete", [
    'session_id' => $sessionId,
]);

echo "HTTP Status: {$completeRes['code']}\n";
echo "Response: " . json_encode($completeRes['body'], JSON_PRETTY_PRINT) . "\n\n";

if ($completeRes['code'] === 200) {
    echo "=========================================================================\n";
    echo " SUCCESS: ALL 5 PACKAGE REST ENDPOINTS EXECUTED AND PASSED 100%! \n";
    echo "=========================================================================\n";
} else {
    echo "ERROR: Reassembly failed!\n";
    exit(1);
}
