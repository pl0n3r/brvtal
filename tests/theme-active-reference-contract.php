<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/content-validation.php';

function theme_reference_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Theme active reference contract failed: {$message}\n");
        exit(1);
    }
}

$called = false;
$other = brvtal_theme_active_reference_error('site', 'anything', static function (string $slug) use (&$called): bool {
    $called = true;
    return false;
});
theme_reference_assert($other === null, 'non-theme.active settings must ignore theme reference validation');
theme_reference_assert($called === false, 'non-theme.active settings must not query theme existence');

$invalid = brvtal_theme_active_reference_error('theme.active', '-invalid', static fn(string $slug): bool => true);
theme_reference_assert(($invalid['error'] ?? '') === 'INVALID_THEME_SLUG', 'invalid active slug must preserve syntax validation');

$existing = brvtal_theme_active_reference_error('theme.active', 'core', static fn(string $slug): bool => $slug === 'core');
theme_reference_assert($existing === null, 'existing theme slug must remain activatable');

$missing = brvtal_theme_active_reference_error('theme.active', 'missing-theme', static fn(string $slug): bool => false);
theme_reference_assert(($missing['error'] ?? '') === 'THEME_NOT_FOUND', 'missing theme slug must be rejected');
theme_reference_assert(($missing['field'] ?? '') === 'setting_value', 'missing theme error must identify setting_value');

$activeDelete = brvtal_theme_delete_reference_error('theme.core', 'core');
theme_reference_assert(($activeDelete['error'] ?? '') === 'ACTIVE_THEME_DELETE_BLOCKED', 'active theme record deletion must be rejected');
$inactiveDelete = brvtal_theme_delete_reference_error('theme.alt', 'core');
theme_reference_assert($inactiveDelete === null, 'inactive theme record deletion may proceed');
$activePointerDelete = brvtal_theme_delete_reference_error('theme.active', 'core');
theme_reference_assert($activePointerDelete === null, 'deleting the pointer itself is not a target-record dangling reference');

$api = file_get_contents(__DIR__ . '/../api/index.php') ?: '';
$settingsUi = file_get_contents(__DIR__ . '/../discadmin/settings-v2.js') ?: '';
theme_reference_assert(str_contains($api, "SELECT setting_value FROM settings WHERE setting_key=? AND is_json=1 LIMIT 1"), 'API must fetch the target theme payload before activation');
theme_reference_assert(str_contains($api, "'theme.'.\$slug"), 'API must query the canonical theme.<slug> key');
theme_reference_assert(str_contains($api, 'json_decode($raw,true)'), 'API must require the target theme JSON to decode before activation.');
theme_reference_assert(str_contains($api, 'return is_array($decoded)'), 'API must reject non-object theme payloads as dangling references.');
theme_reference_assert(str_contains($api, 'brvtal_theme_delete_reference_error'), 'Settings DELETE must protect the active theme record.');
theme_reference_assert(str_contains($api, 'ACTIVE_THEME_DELETE_BLOCKED'), 'API contract must expose an explicit active-theme deletion conflict.');
theme_reference_assert(str_contains($settingsUi, 'data-settings-theme-studio'), 'Settings must route theme.active to Theme Studio');
theme_reference_assert(str_contains($settingsUi, "globalThis.go?.('theme')"), 'Settings must keep Theme Studio inside the canonical shell');

echo "Theme active reference contract OK\n";
