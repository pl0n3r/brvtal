<?php
declare(strict_types=1);

/**
 * Server-side image import from a pasted URL (no Meta/Instagram API).
 *
 * The owner copies an image address (for example an Instagram profile photo)
 * and DISCADMIN asks the server to download it once. Because the server makes
 * the request, every hop is treated as SSRF-sensitive:
 *  - only https URLs on port 443, without credentials;
 *  - the host is resolved once and the connection is pinned to that IP, which
 *    must be public (no private, loopback, link-local, reserved or mapped);
 *  - redirects are followed manually (max 3) and each target is revalidated;
 *  - 10 s timeout and an 8 MB cap enforced while streaming;
 *  - the image type is detected from the bytes (JPEG, PNG or WebP only).
 *
 * Resolver and transport are injectable so contract tests never touch the
 * network.
 */

const BRVTAL_MEDIA_URL_IMPORT_MAX_BYTES = 8 * 1024 * 1024;
const BRVTAL_MEDIA_URL_IMPORT_TIMEOUT_S = 10;
const BRVTAL_MEDIA_URL_IMPORT_MAX_REDIRECTS = 3;
const BRVTAL_MEDIA_URL_IMPORT_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

final class BrvtalMediaUrlImportError extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $httpStatus = 422)
    {
        parent::__construct($errorCode);
    }
}

/** Human-readable Spanish messages for each error code. */
function brvtal_media_url_import_message(string $code): string
{
    return match ($code) {
        'URL_INVALID' => 'El enlace no es válido. Pega la dirección completa de la imagen (https://…).',
        'URL_NOT_HTTPS' => 'Solo se aceptan enlaces https.',
        'URL_HOST_BLOCKED' => 'Ese enlace apunta a una dirección no permitida.',
        'URL_DNS_FAILED' => 'No se encontró el sitio del enlace.',
        'TOO_MANY_REDIRECTS' => 'El enlace redirige demasiadas veces.',
        'REMOTE_TIMEOUT', 'REMOTE_UNREACHABLE' => 'El sitio de la imagen no respondió. Inténtalo de nuevo.',
        'REMOTE_HTTP_ERROR' => 'El sitio no entregó la imagen. Copia de nuevo la dirección de la imagen.',
        'TOO_LARGE' => 'La imagen pesa más de 8 MB.',
        'NOT_AN_IMAGE' => 'El enlace no es una imagen JPG, PNG o WebP.',
        default => 'No se pudo importar la imagen.',
    };
}

/**
 * Validate the shape of a URL. Returns [host, path+query] for an https:443 URL.
 *
 * @return array{host:string, target:string}
 */
function brvtal_media_url_import_parse(string $url): array
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
        throw new BrvtalMediaUrlImportError('URL_INVALID');
    }
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
        throw new BrvtalMediaUrlImportError('URL_INVALID');
    }
    if (strtolower($parts['scheme']) !== 'https') {
        throw new BrvtalMediaUrlImportError('URL_NOT_HTTPS');
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        throw new BrvtalMediaUrlImportError('URL_INVALID');
    }
    if (isset($parts['port']) && (int)$parts['port'] !== 443) {
        throw new BrvtalMediaUrlImportError('URL_HOST_BLOCKED');
    }
    $host = strtolower(trim($parts['host'], '[]'));
    if (preg_match('/[^\x00-\x7f]/', $host) === 1) {
        throw new BrvtalMediaUrlImportError('URL_INVALID');
    }
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
        throw new BrvtalMediaUrlImportError('URL_HOST_BLOCKED');
    }
    $target = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    return ['host' => $host, 'target' => $target];
}

/** True only for globally routable unicast addresses. */
function brvtal_media_url_import_ip_is_public(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return false;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        $long = ip2long($ip);
        // 100.64.0.0/10 CGNAT and 192.0.0.0/24 are not covered by PHP's flags.
        foreach ([['100.64.0.0', 10], ['192.0.0.0', 24], ['192.0.2.0', 24], ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4]] as [$net, $bits]) {
            $mask = -1 << (32 - $bits);
            if (($long & $mask) === (ip2long($net) & $mask)) {
                return false;
            }
        }
        return true;
    }
    $bin = inet_pton($ip);
    if ($bin === false) {
        return false;
    }
    // IPv4-mapped/compatible (::ffff:0:0/96, ::/96), NAT64 64:ff9b::/96, ULA fc00::/7, link-local fe80::/10, multicast ff00::/8.
    if (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff") || str_starts_with($bin, str_repeat("\0", 12))) {
        return false;
    }
    if ((str_starts_with($bin, "\x00\x64\xff\x9b") || str_starts_with($bin, "\x20\x01\x0d\xb8"))) {
        return false;
    }
    $first = ord($bin[0]);
    $second = ord($bin[1]);
    if (($first & 0xfe) === 0xfc || $first === 0xff || ($first === 0xfe && ($second & 0xc0) === 0x80)) {
        return false;
    }
    return true;
}

/**
 * Resolve a host to one public IP. Every resolved address must be public, so a
 * name that mixes public and internal records is rejected.
 */
function brvtal_media_url_import_resolve(string $host, ?callable $resolver = null): string
{
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        $ips = [$host];
    } else {
        $resolver ??= static function (string $name): array {
            $records = @dns_get_record($name, DNS_A | DNS_AAAA) ?: [];
            $ips = [];
            foreach ($records as $record) {
                $ips[] = (string)($record['ip'] ?? $record['ipv6'] ?? '');
            }
            return array_values(array_filter($ips));
        };
        $ips = $resolver($host);
    }
    if ($ips === []) {
        throw new BrvtalMediaUrlImportError('URL_DNS_FAILED');
    }
    foreach ($ips as $ip) {
        if (!brvtal_media_url_import_ip_is_public((string)$ip)) {
            throw new BrvtalMediaUrlImportError('URL_HOST_BLOCKED');
        }
    }
    return (string)$ips[0];
}

/** Confirm complete PNG chunks before accepting a header-only image. */
function brvtal_media_url_import_png_complete(string $bytes): bool
{
    if (!str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
        return false;
    }
    $size = strlen($bytes);
    $offset = 8;
    $seenHeader = false;
    $seenData = false;
    while ($offset + 12 <= $size) {
        $len = unpack('N', substr($bytes, $offset, 4))[1];
        if ($len > $size - $offset - 12) {
            return false;
        }
        $kind = substr($bytes, $offset + 4, 4);
        $data = substr($bytes, $offset + 8, $len);
        $crc = substr($bytes, $offset + 8 + $len, 4);
        if (!hash_equals(pack('N', crc32($kind . $data)), $crc)) {
            return false;
        }
        if (!$seenHeader && ($kind !== 'IHDR' || $len !== 13)) {
            return false;
        }
        if ($kind === 'IHDR' && $seenHeader) {
            return false;
        }
        if ($kind === 'IHDR') {
            $seenHeader = true;
        }
        if ($kind === 'IDAT') {
            $seenData = true;
        }
        $offset += 12 + $len;
        if ($kind === 'IEND') {
            return $seenHeader && $seenData && $len === 0 && $offset === $size;
        }
    }
    return false;
}

/** Detect an allowed image type from the bytes; returns the MIME type. */
function brvtal_media_url_import_detect(string $bytes): string
{
    if ($bytes === '') {
        throw new BrvtalMediaUrlImportError('NOT_AN_IMAGE');
    }
    $mime = new finfo(FILEINFO_MIME_TYPE)->buffer($bytes) ?: '';
    $dimensions = @getimagesizefromstring($bytes);
    if (!isset(BRVTAL_MEDIA_URL_IMPORT_TYPES[$mime]) || $dimensions === false
        || ($dimensions[0] ?? 0) < 1 || ($dimensions[1] ?? 0) < 1
        || $dimensions[0] > intdiv(40000000, $dimensions[1])) {
        throw new BrvtalMediaUrlImportError('NOT_AN_IMAGE');
    }
    if ($mime === 'image/png' && !brvtal_media_url_import_png_complete($bytes)) {
        throw new BrvtalMediaUrlImportError('NOT_AN_IMAGE');
    }
    if (function_exists('imagecreatefromstring')) {
        $decoded = @imagecreatefromstring($bytes);
        if (!$decoded instanceof GdImage) {
            throw new BrvtalMediaUrlImportError('NOT_AN_IMAGE');
        }
        unset($decoded);
    }
    return $mime;
}

/**
 * Default transport: one https GET pinned to $ip, no automatic redirects.
 *
 * @return array{status:int, location:?string, body:string}
 */
function brvtal_media_url_import_curl(string $url, string $host, string $ip, int $timeoutMs = BRVTAL_MEDIA_URL_IMPORT_TIMEOUT_S * 1000): array
{
    if (!function_exists('curl_init') || !defined('CURLOPT_PROTOCOLS_STR')
        || !defined('CURLOPT_REDIR_PROTOCOLS_STR') || $timeoutMs < 1) {
        throw new BrvtalMediaUrlImportError('REMOTE_UNREACHABLE', 502);
    }
    $ch = curl_init($url);
    if ($ch === false) {
        throw new BrvtalMediaUrlImportError('REMOTE_UNREACHABLE', 502);
    }
    $body = '';
    $tooLarge = false;
    $options = [
        CURLOPT_PROXY => '', // Do not let HTTP(S)_PROXY bypass the pinned IP.
        CURLOPT_PROTOCOLS_STR => 'https',
        CURLOPT_REDIR_PROTOCOLS_STR => 'https',
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT_MS => min(5000, $timeoutMs),
        CURLOPT_TIMEOUT_MS => $timeoutMs,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'BRVTAL-MediaImport/1.0',
        CURLOPT_HTTPHEADER => ['Accept: image/jpeg, image/png, image/webp'],
        CURLOPT_HEADER => false,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
            if (strlen($body) + strlen($chunk) > BRVTAL_MEDIA_URL_IMPORT_MAX_BYTES) {
                $tooLarge = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ];
    // IP literals are already resolved and validated; they need no DNS override.
    // For DNS names, pin only the public address that passed the SSRF checks.
    if (filter_var($host, FILTER_VALIDATE_IP) === false) {
        $pin = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $options[CURLOPT_RESOLVE] = [$host . ':443:' . $pin];
    }
    if (curl_setopt_array($ch, $options) === false) {
        throw new BrvtalMediaUrlImportError('REMOTE_UNREACHABLE', 502);
    }
    $ok = curl_exec($ch);
    $errno = curl_errno($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $location = curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: null;
    if ($tooLarge) {
        throw new BrvtalMediaUrlImportError('TOO_LARGE');
    }
    if ($ok === false) {
        throw new BrvtalMediaUrlImportError($errno === CURLE_OPERATION_TIMEDOUT ? 'REMOTE_TIMEOUT' : 'REMOTE_UNREACHABLE', 502);
    }
    return ['status' => $status, 'location' => is_string($location) ? $location : null, 'body' => $body];
}

/**
 * Download and validate an image. Returns the bytes and detected MIME type.
 *
 * @return array{mime:string, bytes:string, extension:string}
 */
function brvtal_media_url_import_fetch(string $url, ?callable $resolver = null, ?callable $transport = null): array
{
    $transport ??= 'brvtal_media_url_import_curl';
    $deadline = hrtime(true) + BRVTAL_MEDIA_URL_IMPORT_TIMEOUT_S * 1_000_000_000;
    for ($hop = 0; $hop <= BRVTAL_MEDIA_URL_IMPORT_MAX_REDIRECTS; $hop++) {
        $parsed = brvtal_media_url_import_parse($url);
        $ip = brvtal_media_url_import_resolve($parsed['host'], $resolver);
        $remainingMs = (int)ceil(($deadline - hrtime(true)) / 1_000_000);
        if ($remainingMs < 1) {
            throw new BrvtalMediaUrlImportError('REMOTE_TIMEOUT', 502);
        }
        $response = $transport($url, $parsed['host'], $ip, $remainingMs);
        $status = (int)($response['status'] ?? 0);
        if ($status >= 300 && $status < 400) {
            $location = (string)($response['location'] ?? '');
            if ($location === '') {
                throw new BrvtalMediaUrlImportError('REMOTE_HTTP_ERROR', 502);
            }
            $url = $location;
            continue;
        }
        if ($status !== 200) {
            throw new BrvtalMediaUrlImportError('REMOTE_HTTP_ERROR', 502);
        }
        $bytes = (string)($response['body'] ?? '');
        if (strlen($bytes) > BRVTAL_MEDIA_URL_IMPORT_MAX_BYTES) {
            throw new BrvtalMediaUrlImportError('TOO_LARGE');
        }
        $mime = brvtal_media_url_import_detect($bytes);
        return ['mime' => $mime, 'bytes' => $bytes, 'extension' => BRVTAL_MEDIA_URL_IMPORT_TYPES[$mime]];
    }
    throw new BrvtalMediaUrlImportError('TOO_MANY_REDIRECTS');
}
