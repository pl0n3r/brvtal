<?php
declare(strict_types=1);

/**
 * Public, read-only SoundCloud artwork lookup. No arbitrary URLs are fetched:
 * the outbound endpoint is always the official HTTPS SoundCloud oEmbed URL.
 */
function brvtal_soundcloud_track_url(mixed $value): string
{
    if (!is_string($value) || strlen($value) > 512) return '';
    $parsed = parse_url(trim($value));
    if (!is_array($parsed) || strtolower((string)($parsed['scheme'] ?? '')) !== 'https') return '';
    if (!in_array(strtolower((string)($parsed['host'] ?? '')), ['soundcloud.com', 'www.soundcloud.com'], true)) return '';
    if (isset($parsed['user'], $parsed['pass']) || isset($parsed['user']) || isset($parsed['port'])) return '';
    $pieces = array_values(array_filter(explode('/', trim((string)($parsed['path'] ?? ''), '/')), 'strlen'));
    if (count($pieces) !== 2 && !(count($pieces) === 3 && $pieces[1] === 'sets')) return '';
    foreach ($pieces as $piece) if (!preg_match('/^[A-Za-z0-9_-]{1,120}$/D', $piece)) return '';
    if (in_array(strtolower($pieces[0]), ['discover', 'stream', 'search', 'you', 'charts', 'upload', 'tracks', 'settings'], true)) return '';
    return 'https://soundcloud.com/' . implode('/', $pieces);
}

function brvtal_soundcloud_artwork_url(mixed $value): string
{
    if (!is_string($value) || strlen($value) > 700) return '';
    $url = parse_url($value);
    if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || isset($url['user']) || isset($url['port'])) return '';
    $host = strtolower((string)($url['host'] ?? ''));
    $path = (string)($url['path'] ?? '');
    if (!preg_match('/^i[0-9]{1,2}\.sndcdn\.com$/D', $host)) return '';
    // Avatar images are not the Set artwork requested by the owner.
    if (!preg_match('#^/artworks-[A-Za-z0-9_-]+(?:-t[0-9]+x[0-9]+)?\.(?:jpg|jpeg|png|webp)$#D', $path)) return '';
    return 'https://' . $host . $path;
}

/** @return array{ok:bool,artwork_url?:string,error?:string} */
function brvtal_soundcloud_resolve(string $track, callable $request): array
{
    $endpoint = 'https://soundcloud.com/oembed?' . http_build_query(
        ['format' => 'json', 'url' => $track], '', '&', PHP_QUERY_RFC3986
    );
    [$status, $body] = $request($endpoint);
    if ($status === 404) return ['ok' => false, 'error' => 'TRACK_UNAVAILABLE'];
    if ($status !== 200 || !is_string($body) || strlen($body) > 65536) {
        return ['ok' => false, 'error' => 'PROVIDER_UNAVAILABLE'];
    }
    $metadata = json_decode($body, true);
    if (!is_array($metadata) || ($metadata['provider_name'] ?? '') !== 'SoundCloud') {
        return ['ok' => false, 'error' => 'INVALID_PROVIDER'];
    }
    return ['ok' => true, 'artwork_url' => brvtal_soundcloud_artwork_url($metadata['thumbnail_url'] ?? null)];
}

/** @return array{ok:bool,artwork_url?:string,error?:string} */
function brvtal_soundcloud_lookup(string $track, ?callable $request = null, ?string $cacheDir = null): array
{
    $directory = $cacheDir ?? sys_get_temp_dir();
    $cacheFile = rtrim($directory, '/') . '/brvtal-soundcloud-' . hash('sha256', $track) . '.json';
    if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < 21600) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['ok']) && is_bool($cached['ok'])) return $cached;
    }

    $request ??= static function (string $endpoint): array {
        if (!function_exists('curl_init')) return [0, ''];
        $curl = curl_init($endpoint);
        if ($curl === false) return [0, ''];
        $body = '';
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'BRVTAL-SoundCloud-Artwork/1.0',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 65536) return 0;
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        if (defined('CURLOPT_PROTOCOLS_STR')) curl_setopt($curl, CURLOPT_PROTOCOLS_STR, 'https');
        elseif (defined('CURLOPT_PROTOCOLS')) curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        unset($curl);
        return [$status, $body];
    };

    $result = brvtal_soundcloud_resolve($track, $request);
    if ($result['ok'] || ($result['error'] ?? '') === 'TRACK_UNAVAILABLE') {
        // Cache both valid tracks and genuine 404s, never temporary provider failures.
        if (is_dir($directory) && is_writable($directory)) {
            $temp = tempnam($directory, 'brvtal-sc-');
            if ($temp !== false) {
                file_put_contents($temp, json_encode($result, JSON_UNESCAPED_SLASHES), LOCK_EX);
                rename($temp, $cacheFile);
            }
        }
    }
    return $result;
}

// Including this module from a CLI test must not execute its HTTP controller.
if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) return;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=900');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}
$track = brvtal_soundcloud_track_url($_GET['url'] ?? null);
if ($track === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'INVALID_TRACK_URL']);
    exit;
}
$result = brvtal_soundcloud_lookup($track);
if (!$result['ok']) http_response_code(($result['error'] ?? '') === 'TRACK_UNAVAILABLE' ? 404 : 503);
echo json_encode($result, JSON_UNESCAPED_SLASHES);
