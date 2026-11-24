<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$dataDir = getenv('RATINGS_DIR') ?: '/var/lib/test-electoral-espana';
$dataFile = $dataDir . '/ratings.json';

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function stats(array $data): array
{
    $count = 0;
    $sum = 0;
    $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];

    foreach ($data as $entry) {
        $rating = (int) ($entry['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) {
            continue;
        }
        $count++;
        $sum += $rating;
        $distribution[$rating]++;
    }

    return [
        'ok' => true,
        'count' => $count,
        'average' => $count > 0 ? round($sum / $count, 2) : 0,
        'distribution' => $distribution,
    ];
}

function loadData(string $file): array
{
    if (!is_file($file)) {
        return [];
    }

    $raw = file_get_contents($file);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    respond(200, stats(loadData($dataFile)));
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 1024) {
    respond(400, ['ok' => false, 'error' => 'invalid_payload']);
}

$body = json_decode($raw, true);
if (!is_array($body)) {
    respond(400, ['ok' => false, 'error' => 'invalid_json']);
}

$rating = $body['rating'] ?? null;
$clientId = $body['clientId'] ?? null;

if (is_string($rating) && ctype_digit($rating)) {
    $rating = (int) $rating;
}
if (!is_int($rating) || $rating < 1 || $rating > 5) {
    respond(422, ['ok' => false, 'error' => 'invalid_rating']);
}

if (!is_string($clientId) || !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $clientId)) {
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
$data = [];
if ($existing !== false && trim($existing) !== '') {
    $decoded = json_decode($existing, true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}

$data[$clientId] = ['rating' => $rating, 'ts' => time()];
$encoded = json_encode($data, JSON_UNESCAPED_UNICODE);

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

respond(200, stats($data) + ['userRating' => $rating]);
