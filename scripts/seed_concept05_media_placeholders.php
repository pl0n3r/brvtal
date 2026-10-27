<?php
declare(strict_types=1);
/**
 * BRVTAL Concept 05 synthetic sources -> isolated Media Library fixture.
 * --dry-run (default) never contacts a server. --apply requires synthetic
 * authenticated session + CSRF and a loopback host; production is forbidden.
 */
const C05_SOURCE_NAMES = ['hero-face', 'crowd', 'artists', 'nights', 'memories'];

function c05Sources(): array
{
    $result = [];
    foreach (C05_SOURCE_NAMES as $name) {
        $source = dirname(__DIR__) . "/assets/placeholders/concept05/{$name}.webp";
        if (!is_file($source) || !is_readable($source)) {
            throw new RuntimeException("MISSING_SOURCE:{$name}");
        }
        $bytes = file_get_contents($source);
        if (!is_string($bytes) || !str_starts_with($bytes, 'RIFF')
            || substr($bytes, 8, 4) !== 'WEBP') {
            throw new RuntimeException("INVALID_WEBP:{$name}");
        }
        $result[$name] = [
            'source' => $source,
            'sha256' => hash('sha256', $bytes),
            'size' => strlen($bytes),
        ];
    }
    if (count(array_unique(array_column($result, 'sha256'))) !== 5) {
        throw new RuntimeException('DUPLICATE_SOURCE_HASH');
    }
    return $result;
}

function c05FixtureRequest(string $origin, string $path, string $method, array $post, string $cookie, string $csrf): array
{
    $url = $origin . '/api/media-library.php' . $path;
    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('CURL_INIT_FAILED');
    $headers = ['X-CSRF-Token: ' . $csrf, 'Accept: application/json'];
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    } else {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post, JSON_THROW_ON_ERROR));
    }
    curl_setopt_array($ch, [
        CURLOPT_COOKIE => $cookie,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 25,
    ]);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $json = is_string($response) ? json_decode($response, true) : null;
    if (!is_array($json) || ($json['ok'] ?? false) !== true || $status < 200 || $status > 299) {
        throw new RuntimeException("FIXTURE_API_FAILED:{$status}:" . (is_array($json) ? ($json['error'] ?? '') : 'INVALID_JSON'));
    }
    return $json;
}

try {
    $sources = c05Sources();
    $apply = in_array('--apply', $argv, true);
    if (!$apply) {
        echo json_encode(['mode' => 'dry-run', 'synthetic_only' => true, 'sources' => $sources], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
        exit(0);
    }
    $origin = (string)(getenv('BRVTAL_C05_FIXTURE_ORIGIN') ?: '');
    $cookie = (string)(getenv('BRVTAL_C05_FIXTURE_COOKIE') ?: '');
    $csrf = (string)(getenv('BRVTAL_C05_FIXTURE_CSRF') ?: '');
    if (!preg_match('~^http://127\.0\.0\.1:[0-9]{2,5}$~D', $origin)
        || $cookie === '' || $csrf === '' || !extension_loaded('curl')) {
        throw new RuntimeException('ISOLATED_AUTHENTICATED_FIXTURE_REQUIRED');
    }
    $published = [];
    foreach ($sources as $name => $entry) {
        $uploaded = c05FixtureRequest($origin, '?action=upload', 'POST', [
            'file' => new CURLFile($entry['source'], 'image/webp', "c05-{$name}.webp"),
            'title' => "C05 SYNTHETIC {$name}",
            'alt_text' => "Synthetic BRVTAL editorial {$name}",
        ], $cookie, $csrf);
        $media = $uploaded['data'] ?? [];
        $id = (int)($media['id'] ?? 0);
        $path = (string)($media['file_path'] ?? '');
        if ($id < 1 || !preg_match('~^/uploads/media/[A-Za-z0-9/_-]+\.webp$~D', $path)) {
            throw new RuntimeException("INVALID_MEDIA_RECORD:{$name}");
        }
        if (($media['status'] ?? 'draft') !== 'published') {
            $update = c05FixtureRequest($origin, '?action=update&id=' . $id, 'PUT', [
                'title' => "C05 SYNTHETIC {$name}",
                'status' => 'published',
                'alt_text' => "Synthetic BRVTAL editorial {$name}",
            ], $cookie, $csrf);
            if (($update['data']['status'] ?? '') !== 'published') {
                throw new RuntimeException("PUBLISH_FAILED:{$name}");
            }
        }
        $published[$name] = ['id' => $id, 'url' => $path, 'sha256' => $entry['sha256']];
    }
    if (count(array_unique(array_column($published, 'id'))) !== 5) {
        throw new RuntimeException('MEDIA_IDS_MUST_BE_DISTINCT');
    }
    echo json_encode(['mode' => 'isolated-fixture-only', 'synthetic_only' => true, 'published' => $published], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, "C05_MEDIA_IMPORT_ABORTED: " . $error->getMessage() . PHP_EOL);
    exit(1);
}
