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
$other = brvtalThemeActiveReferenceError('site', 'anything', static function (string $slug) use (&$called): bool {
    $called = true;
    return false;
});
theme_reference_assert($other === null, 'non-theme.active settings must ignore theme reference validation');
theme_reference_assert($called === false, 'non-theme.active settings must not query theme existence');

$invalid = brvtalThemeActiveReferenceError('theme.active', '-invalid', static fn(string $slug): bool => true);
theme_reference_assert(($invalid['error'] ?? '') === 'INVALID_THEME_SLUG', 'invalid active slug must preserve syntax validation');

$existing = brvtalThemeActiveReferenceError('theme.active', 'core', static fn(string $slug): bool => $slug === 'core');
theme_reference_assert($existing === null, 'existing theme slug must remain activatable');

$missing = brvtalThemeActiveReferenceError('theme.active', 'missing-theme', static fn(string $slug): bool => false);
theme_reference_assert(($missing['error'] ?? '') === 'THEME_NOT_FOUND', 'missing theme slug must be rejected');
theme_reference_assert(($missing['field'] ?? '') === 'setting_value', 'missing theme error must identify setting_value');

$activeDelete = brvtalThemeDeleteReferenceError('theme.core', 'core');
theme_reference_assert(($activeDelete['error'] ?? '') === 'ACTIVE_THEME_DELETE_BLOCKED', 'active theme record deletion must be rejected');
$inactiveDelete = brvtalThemeDeleteReferenceError('theme.alt', 'core');
theme_reference_assert($inactiveDelete === null, 'inactive theme record deletion may proceed');
$activePointerDelete = brvtalThemeDeleteReferenceError('theme.active', 'core');
theme_reference_assert($activePointerDelete === null, 'deleting the pointer itself is not a target-record dangling reference');

$api = file_get_contents(__DIR__ . '/../api/index.php') ?: '';
$settingsUi = file_get_contents(__DIR__ . '/../discadmin/settings-v2.js') ?: '';
theme_reference_assert(str_contains($api, "SELECT setting_value FROM settings WHERE setting_key=? AND is_json=1 LIMIT 1"), 'API must fetch the target theme payload before activation');
theme_reference_assert(str_contains($api, "'theme.' . \$slug"), 'API must query the canonical theme.<slug> key');
theme_reference_assert(str_contains($api, 'json_decode($raw, true)'), 'API must require the target theme JSON to decode before activation.');
theme_reference_assert(str_contains($api, 'return is_array($decoded)'), 'API must reject non-object theme payloads as dangling references.');
theme_reference_assert(str_contains($api, 'brvtalThemeDeleteReferenceError'), 'Settings DELETE must protect the active theme record.');
$settingsPostStart = strpos($api, "if ($resource === 'settings') {");
$settingsPostEnd = strpos($api, '$d=sanitize_payload', $settingsPostStart);
$settingsPostBlock = $settingsPostStart !== false && $settingsPostEnd !== false
    ? substr($api, $settingsPostStart, $settingsPostEnd - $settingsPostStart)
    : '';
$settingsDeleteStart = strpos($api, "if ($method === 'DELETE' && $resource === 'settings' && $id === null)");
$settingsDeleteEnd = strpos($api, 'method_not_allowed();', $settingsDeleteStart);
$settingsDeleteBlock = $settingsDeleteStart !== false && $settingsDeleteEnd !== false
    ? substr($api, $settingsDeleteStart, $settingsDeleteEnd - $settingsDeleteStart)
    : '';
theme_reference_assert(str_contains($api, 'SELECT GET_LOCK(?, 5)'), 'Theme mutations must acquire a cross-session Theme mutex');
theme_reference_assert(str_contains($api, 'SELECT RELEASE_LOCK(?)'), 'Theme mutations must release the cross-session Theme mutex');
theme_reference_assert(str_contains($settingsPostBlock, 'brvtalAcquireThemeReferenceMutex($pdo)'), 'Settings POST must serialize theme writes before reference validation');
theme_reference_assert(str_contains($settingsPostBlock, 'LIMIT 1 FOR UPDATE'), 'theme.active activation must lock the target theme row inside its transaction');
theme_reference_assert(str_contains($settingsDeleteBlock, "brvtal_setting_key_normalize((string)(\$_GET['key'] ?? ''))"), 'Settings DELETE must apply the canonical setting-key validator before database access');
theme_reference_assert(str_contains($settingsDeleteBlock, 'brvtalAcquireThemeReferenceMutex($pdo)'), 'Settings DELETE must share the same Theme mutation mutex');
theme_reference_assert(str_contains($settingsDeleteBlock, "setting_key='theme.active' LIMIT 1 FOR UPDATE"), 'Settings DELETE must lock theme.active before checking the protected target');
theme_reference_assert(str_contains($settingsDeleteBlock, '$pdo->beginTransaction();'), 'Theme Settings DELETE must check and delete inside one transaction');
theme_reference_assert(str_contains($api, '409'), 'Settings DELETE must return an HTTP 409 conflict for the protected active theme.');
theme_reference_assert(str_contains($settingsUi, 'data-settings-theme-studio'), 'Settings must route theme.active to Theme Studio');
theme_reference_assert(str_contains($settingsUi, "globalThis.go?.('theme')"), 'Settings must keep Theme Studio inside the canonical shell');

echo "Theme active reference contract OK\n";
