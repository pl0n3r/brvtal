<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$index = (string) file_get_contents($root . '/discadmin/index.php');
$authBoundary = (string) file_get_contents($root . '/discadmin/admin-auth-boundary.js');
$reliability = (string) file_get_contents($root . '/discadmin/admin-reliability.js');
$stateBridge = (string) file_get_contents($root . '/discadmin/hero-slider-state-bridge.js');
$totp = (string) file_get_contents($root . '/discadmin/totp-login.js');

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$expect(
    str_contains($index, 'window.csrf=\'\';window.state='),
    'Canonical DISCADMIN output must expose csrf/state as explicit window properties'
);
$expect(
    str_contains($index, '$runtimeReplacementCount !== 1'),
    'Canonical DISCADMIN must fail closed when the runtime declaration cannot be rewritten exactly once'
);

foreach ([
    'admin-auth-boundary.js' => $authBoundary,
    'admin-reliability.js' => $reliability,
    'hero-slider-state-bridge.js' => $stateBridge,
    'totp-login.js' => $totp,
] as $name => $source) {
    $expect(
        preg_match('/(?<![.A-Za-z0-9_$])csrf\s*=/', $source) !== 1,
        $name . ' must not assign csrf as an implicit global'
    );
    $expect(
        preg_match('/(?<![.A-Za-z0-9_$])state\s*=/', $source) !== 1,
        $name . ' must not assign state as an implicit global'
    );
}

$expect(str_contains($authBoundary, "window.csrf = '';"), 'Auth boundary must clear the explicit CSRF property');
$expect(str_contains($reliability, 'window.state.authed = true;'), 'Reliable login must update explicit shell auth state');
$expect(str_contains($reliability, "window.csrf = response.csrf || '';"), 'Reliable login must update explicit shell CSRF');
$expect(str_contains($totp, 'window.BRVTALAdminModules && window.state'), 'TOTP override must require the canonical explicit state surface');
$expect(str_contains($totp, "window.csrf = d.csrf || '';"), 'TOTP restore must update explicit shell CSRF');
$expect(str_contains($stateBridge, "hasOwnProperty.call(window, 'state')"), 'Hero Slider bridge must consume the explicit state surface');

echo "BRVTAL explicit admin runtime contract tests passed.\n";
