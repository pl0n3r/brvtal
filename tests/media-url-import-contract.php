<?php
declare(strict_types=1);

/**
 * Media URL import contract (#1751).
 *
 * Exercises the real importer with an injected resolver and transport, so no
 * network, credentials or production data are involved.
 */
require_once __DIR__ . '/../config/media_url_import.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "Media URL import contract failed: {$message}\n");
        exit(1);
    }
};

$expectError = static function (callable $fn, string $code, string $label) use ($assert): void {
    try {
        $fn();
    } catch (BrvtalMediaUrlImportError $e) {
        $assert($e->errorCode === $code, "{$label}: expected {$code}, got {$e->errorCode}");
        return;
    }
    $assert(false, "{$label}: expected {$code}, nothing was thrown");
};

// Synthetic images generated in memory.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
$gd = function_exists('imagecreatetruecolor');
$jpeg = null;
$webp = null;
if ($gd) {
    $img = imagecreatetruecolor(2, 2);
    ob_start(); imagejpeg($img); $jpeg = (string)ob_get_clean();
    if (function_exists('imagewebp')) { ob_start(); imagewebp($img); $webp = (string)ob_get_clean(); }
}

$publicResolver = static fn(string $host): array => ['93.184.216.34'];
$serve = static fn(array $routes): callable => static fn(string $url, string $host, string $ip): array
    => $routes[$url] ?? ['status' => 404, 'location' => null, 'body' => ''];

// [AC-01] URL shape: only https:443 without credentials or local hosts.
foreach ([
    ['http://cdn.example.com/a.png', 'URL_NOT_HTTPS'],
    ['ftp://cdn.example.com/a.png', 'URL_NOT_HTTPS'],
    ['not a url', 'URL_INVALID'],
    ['', 'URL_INVALID'],
    ['https://user:pw@cdn.example.com/a.png', 'URL_INVALID'],
    ['https://cdn.example.com:8443/a.png', 'URL_HOST_BLOCKED'],
    ['https://localhost/a.png', 'URL_HOST_BLOCKED'],
    ['https://bücher.example/a.png', 'URL_INVALID'],
    ['https://printer.local/a.png', 'URL_HOST_BLOCKED'],
] as [$url, $code]) {
    $expectError(static fn() => brvtal_media_url_import_fetch($url, $publicResolver, $serve([])), $code, $url);
}

// [AC-01] Private, loopback, link-local, metadata, CGNAT and mapped addresses are blocked.
foreach (['127.0.0.1', '10.0.0.5', '172.16.3.4', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '224.0.0.1', '192.0.2.1', '198.51.100.1', '203.0.113.1', '2001:db8::1',
          '::1', 'fe80::1', 'fd00::1', '::ffff:127.0.0.1', '64:ff9b::7f00:1'] as $ip) {
    $assert(!brvtal_media_url_import_ip_is_public($ip), "{$ip} must not be public");
    $expectError(
        static fn() => brvtal_media_url_import_fetch('https://evil.example/a.png', static fn() => [$ip], $serve([])),
        'URL_HOST_BLOCKED',
        "resolved {$ip}"
    );
}
foreach (['93.184.216.34', '157.240.22.63', '198.51.101.1', '203.0.114.1', '2a03:2880:f12f:83:face:b00c:0:25de'] as $ip) {
    $assert(brvtal_media_url_import_ip_is_public($ip), "{$ip} must be public");
}
// A name with one public and one internal record is rejected (no partial trust).
$expectError(
    static fn() => brvtal_media_url_import_fetch('https://mixed.example/a.png', static fn() => ['93.184.216.34', '10.0.0.1'], $serve([])),
    'URL_HOST_BLOCKED',
    'mixed DNS answer'
);
$expectError(
    static fn() => brvtal_media_url_import_fetch('https://nx.example/a.png', static fn() => [], $serve([])),
    'URL_DNS_FAILED',
    'empty DNS answer'
);

// [AC-01] The transport is pinned to the validated IP.
$seen = [];
$pinCheck = static function (string $url, string $host, string $ip) use (&$seen, $png): array {
    $seen[] = [$host, $ip];
    return ['status' => 200, 'location' => null, 'body' => $png];
};
brvtal_media_url_import_fetch('https://cdn.example.com/p.png', $publicResolver, $pinCheck);
$assert($seen === [['cdn.example.com', '93.184.216.34']], 'transport must receive the host and its validated IP');

// [AC-01] Redirects are revalidated hop by hop and capped at 3.
$expectError(
    static fn() => brvtal_media_url_import_fetch(
        'https://cdn.example.com/r',
        static fn(string $h): array => $h === 'internal.example' ? ['127.0.0.1'] : ['93.184.216.34'],
        $serve(['https://cdn.example.com/r' => ['status' => 302, 'location' => 'https://internal.example/x', 'body' => '']])
    ),
    'URL_HOST_BLOCKED',
    'redirect to loopback'
);
$expectError(
    static fn() => brvtal_media_url_import_fetch(
        'https://cdn.example.com/r',
        $publicResolver,
        $serve(['https://cdn.example.com/r' => ['status' => 301, 'location' => 'http://cdn.example.com/a.png', 'body' => '']])
    ),
    'URL_NOT_HTTPS',
    'redirect downgrade to http'
);
$loop = $serve([
    'https://a.example/1' => ['status' => 302, 'location' => 'https://a.example/2', 'body' => ''],
    'https://a.example/2' => ['status' => 302, 'location' => 'https://a.example/3', 'body' => ''],
    'https://a.example/3' => ['status' => 302, 'location' => 'https://a.example/4', 'body' => ''],
    'https://a.example/4' => ['status' => 302, 'location' => 'https://a.example/5', 'body' => ''],
]);
$expectError(static fn() => brvtal_media_url_import_fetch('https://a.example/1', $publicResolver, $loop), 'TOO_MANY_REDIRECTS', 'redirect loop');
$ok = brvtal_media_url_import_fetch('https://a.example/1', $publicResolver, $serve([
    'https://a.example/1' => ['status' => 302, 'location' => 'https://b.example/img', 'body' => ''],
    'https://b.example/img' => ['status' => 200, 'location' => null, 'body' => $png],
]));
$assert($ok['mime'] === 'image/png', 'a valid redirect chain must return the image');

// [AC-01] A header-only PNG is not a usable image (no IDAT/IEND).
$truncated = substr($png, 0, 33);
$expectError(static fn() => brvtal_media_url_import_detect($truncated), 'NOT_AN_IMAGE', 'truncated PNG');
$assert(brvtal_media_url_import_png_complete($png), 'complete PNG must be accepted');
$assert(!brvtal_media_url_import_png_complete($truncated), 'header-only PNG must fail');

// [AC-01] Size cap and content sniffing.
$expectError(
    static fn() => brvtal_media_url_import_fetch('https://cdn.example.com/big', $publicResolver, $serve([
        'https://cdn.example.com/big' => ['status' => 200, 'location' => null, 'body' => $png . str_repeat("\0", BRVTAL_MEDIA_URL_IMPORT_MAX_BYTES)],
    ])),
    'TOO_LARGE',
    'over 8 MB'
);
foreach (['<html><body>login</body></html>', '<svg xmlns="http://www.w3.org/2000/svg"/>', "GIF89a\x01\x00\x01\x00\x00\x00\x00;", ''] as $i => $body) {
    $expectError(
        static fn() => brvtal_media_url_import_fetch('https://cdn.example.com/fake.jpg', $publicResolver, $serve([
            'https://cdn.example.com/fake.jpg' => ['status' => 200, 'location' => null, 'body' => $body],
        ])),
        'NOT_AN_IMAGE',
        "non-image body #{$i}"
    );
}
$expectError(
    static fn() => brvtal_media_url_import_fetch('https://cdn.example.com/gone', $publicResolver, $serve([])),
    'REMOTE_HTTP_ERROR',
    'remote 404'
);
$accepted = ['image/png' => $png];
if ($jpeg !== null) { $accepted['image/jpeg'] = $jpeg; }
if ($webp !== null) { $accepted['image/webp'] = $webp; }
foreach ($accepted as $mime => $bytes) {
    $result = brvtal_media_url_import_fetch('https://cdn.example.com/x', $publicResolver, $serve([
        'https://cdn.example.com/x' => ['status' => 200, 'location' => null, 'body' => $bytes],
    ]));
    $assert($result['mime'] === $mime && $result['bytes'] === $bytes, "{$mime} must be accepted unchanged");
}
foreach (['URL_INVALID', 'URL_NOT_HTTPS', 'URL_HOST_BLOCKED', 'TOO_LARGE', 'NOT_AN_IMAGE', 'REMOTE_TIMEOUT'] as $code) {
    $assert(brvtal_media_url_import_message($code) !== brvtal_media_url_import_message('OTHER'), "{$code} needs a specific message");
}

// [AC-02] Endpoint order: session first, POST only, CSRF before any download.
$api = (string)file_get_contents(__DIR__ . '/../api/media-import-url.php');
$authAt = strpos($api, 'brvtal_admin_require();');
$methodAt = strpos($api, "!== 'POST'");
$csrfAt = strpos($api, 'brvtal_admin_require_csrf();');
$fetchAt = strpos($api, 'brvtal_media_url_import_fetch(');
$assert($authAt !== false && $methodAt !== false && $csrfAt !== false && $fetchAt !== false, 'endpoint must authenticate, restrict method, check CSRF and fetch');
$assert($authAt < $methodAt && $methodAt < $csrfAt && $csrfAt < $fetchAt, 'auth → POST → CSRF must all run before the server makes any request');
$assert(substr_count($api, 'brvtal_media_url_import_fetch(') === 1 && !str_contains($api, 'file_get_contents($url'), 'downloads must only go through the guarded importer');
$assert(str_contains($api, "header('X-Content-Type-Options: nosniff');") && str_contains($api, "sandbox"), 'image responses must be nosniff and sandboxed');
$assert(str_contains($api, "header('Content-Type: ' . \$image['mime']);"), 'Content-Type must come from detected bytes');
$config = (string)file_get_contents(__DIR__ . '/../config/media_url_import.php');
$assert(str_contains($config, 'CURLOPT_RESOLVE') && str_contains($config, 'CURLOPT_FOLLOWLOCATION => false') && str_contains($config, "CURLOPT_PROTOCOLS_STR => 'https'"), 'curl must be pinned, https-only and without automatic redirects');
$assert(str_contains($config, "CURLOPT_PROXY => ''") && str_contains($config, 'curl_setopt_array($ch, $options) === false'), 'curl setup must fail closed without environment proxy');
$assert(str_contains($api, "is_string(\$input['url'] ?? null)"), 'request URL must be a string');
$budgets = [];
brvtal_media_url_import_fetch('https://a.example/1', $publicResolver,
    static function (string $url, string $host, string $ip, int $timeoutMs) use (&$budgets, $png): array {
        $budgets[] = $timeoutMs;
        usleep(15000);
        return $url === 'https://a.example/1'
            ? ['status' => 302, 'location' => 'https://b.example/img', 'body' => '']
            : ['status' => 200, 'location' => null, 'body' => $png];
    });
$assert(count($budgets) === 2 && $budgets[0] <= 10000 && $budgets[1] < $budgets[0], 'redirects share one ten-second budget');

// BRVTAL #1782 AC-02: E2E-only injection cannot bypass SSRF when disabled.
putenv('BRVTAL_MEDIA_URL_IMPORT_E2E_FIXTURE');
putenv('BRVTAL_TEST_DB_NAME');
$expectError(
    static fn() => brvtal_media_url_import_fetch('https://127.0.0.1/artist.png'),
    'URL_HOST_BLOCKED',
    'loopback blocked without fixture variable'
);
$expectError(
    static fn() => brvtal_media_url_import_fetch(
        'https://fixture.brvtal.test/artist.png',
        static fn() => ['10.10.0.4'],
        $serve([])
    ),
    'URL_HOST_BLOCKED',
    'private DNS result blocked without fixture variable'
);
$assert(
    brvtal_media_url_import_e2e_fixture('https://fixture.brvtal.test/artist.png') === null,
    'fixture is disabled in the normal PHP CLI contract runner'
);

echo "Media URL import contract passed\n";
