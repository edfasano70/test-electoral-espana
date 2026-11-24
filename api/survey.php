<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$dataDir = getenv('SURVEYS_DIR') ?: '/var/lib/test-electoral-espana';
$dataFile = $dataDir . '/surveys.json';

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function readCount(string $file): int
{
    if (!is_file($file)) {
        return 0;
    }

    $handle = @fopen($file, 'r');
    if ($handle === false) {
        return 0;
    }

    flock($handle, LOCK_SH);
    $raw = stream_get_contents($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    if ($raw === false || trim($raw) === '') {
        return 0;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['count'])) {
        return 0;
    }

    return max(0, (int) $decoded['count']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    respond(200, ['ok' => true, 'count' => readCount($dataFile)]);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 1024) {
    respond(400, ['ok' => false, 'error' => 'invalid_payload']);
}

$body = [];
if (trim($raw) !== '') {
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        respond(400, ['ok' => false, 'error' => 'invalid_json']);
    }
    $body = $decoded;
}

$clientId = $body['clientId'] ?? null;
if ($clientId !== null && (!is_string($clientId) || !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $clientId))) {
    respond(422, ['ok' => false, 'error' => 'invalid_client']);
}

if (!is_dir($dataDir) && !mkdir($dataDir, 0775, true) && !is_dir($dataDir)) {
    respond(500, ['ok' => false, 'error' => 'storage_unavailable']);
}

$handle = fopen($dataFile, 'c+');
if ($handle === false) {
    respond(500, ['ok' => false, 'error' => 'storage_unavailable']);
}

flock($handle, LOCK_EX);

$existing = stream_get_contents($handle);
$count = 0;
if ($existing !== false && trim($existing) !== '') {
    $decoded = json_decode($existing, true);
    if (is_array($decoded) && isset($decoded['count'])) {
        $count = max(0, (int) $decoded['count']);
    }
}

$count++;
$encoded = json_encode(['count' => $count, 'updatedAt' => time()], JSON_UNESCAPED_UNICODE);

$stored = false;
if ($encoded !== false) {
    rewind($handle);
    ftruncate($handle, 0);
    $stored = fwrite($handle, $encoded) !== false;
    fflush($handle);
}

flock($handle, LOCK_UN);
fclose($handle);

if (!$stored) {
    respond(500, ['ok' => false, 'error' => 'storage_unavailable']);
}

respond(200, ['ok' => true, 'count' => $count]);
