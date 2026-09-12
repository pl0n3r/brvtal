<?php
declare(strict_types=1);

function activity_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "ADMIN ACTIVITY CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$migration = (string)file_get_contents($root . '/database/migration_admin_activity_01.sql');
$helper = (string)file_get_contents($root . '/config/admin_activity.php');
$endpoint = (string)file_get_contents($root . '/api/admin-activity.php');
$core = (string)file_get_contents($root . '/api/index.php');
$releases = (string)file_get_contents($root . '/api/releases.php');
$blog = (string)file_get_contents($root . '/api/blog.php');
$seo = (string)file_get_contents($root . '/api/seo-metadata.php');
$bulk = (string)file_get_contents($root . '/api/bulk-actions.php');
$shell = (string)file_get_contents($root . '/discadmin/index.php');
$ui = (string)file_get_contents($root . '/discadmin/admin-activity.js');

activity_assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS admin_activity_log'), 'migration must create an idempotent activity table');
activity_assert(str_contains($migration, 'ON DELETE SET NULL'), 'admin deletion must preserve history while clearing the FK');
activity_assert(str_contains($migration, 'idx_admin_activity_resource'), 'resource history must be indexed');
activity_assert(str_contains($migration, 'idx_admin_activity_admin'), 'admin history must be indexed');
activity_assert(!preg_match('/\b(?:DROP\s+TABLE|TRUNCATE\s+TABLE|DELETE\s+FROM)\b/i', $migration), 'migration must be additive and non-destructive');

activity_assert(str_contains($helper, 'brvtal_activity_allowed_fields'), 'helper must use explicit editorial allowlists');
activity_assert(str_contains($helper, 'brvtal_activity_is_sensitive_key'), 'helper must strip sensitive keys recursively');
foreach (['password','secret','token','csrf','recovery','session','credential','authorization'] as $word) {
    activity_assert(str_contains($helper, $word), "sensitive-key guard must cover {$word}");
}
foreach (['events','artists','sets','pages','ticket_types','releases','blog','event_lineup'] as $resource) {
    activity_assert(str_contains($helper, "'{$resource}' =>"), "activity snapshots must explicitly allowlist {$resource}");
}
activity_assert(!str_contains($helper, "'settings' =>"), 'raw Settings values must remain outside the audit snapshot allowlist');
activity_assert(str_contains($helper, 'admin_name'), 'activity rows must preserve an actor name snapshot');
activity_assert(str_contains($helper, 'admin_email'), 'activity rows must preserve an actor email snapshot');
activity_assert(str_contains($helper, 'request_id'), 'activity rows must include a request correlation id');

activity_assert(str_contains($endpoint, 'brvtal_admin_require();'), 'activity endpoint must require authentication');
activity_assert(str_contains($endpoint, "'GET'"), 'activity endpoint must be read-only GET');
activity_assert(str_contains($endpoint, 'ACTIVITY_SCHEMA_MISSING'), 'activity endpoint must fail explicitly before migration');
activity_assert(!str_contains($endpoint, 'brvtal_admin_require_csrf'), 'read-only activity endpoint must not expose a mutation path');
activity_assert(!preg_match('/\b(?:INSERT\s+INTO|UPDATE\s+admin_activity_log|DELETE\s+FROM\s+admin_activity_log)\b/i', $endpoint), 'activity endpoint must never mutate audit rows');

activity_assert(str_contains($core, "'lineup_update'"), 'event lineup changes must be audited');
activity_assert(str_contains($core, "['events','artists','sets','pages','ticket_types']"), 'core editorial resources must be audited');
activity_assert(str_contains($releases, "'releases'"), 'release mutations must write activity history');
activity_assert(str_contains($blog, "'blog'"), 'blog mutations must write activity history');
activity_assert(str_contains($seo, "'seo_update'"), 'SEO metadata changes must be audited');
activity_assert(str_contains($bulk, "'bulk_status'"), 'bulk status changes must be audited');

activity_assert(str_contains($shell, '/discadmin/admin-activity.js'), 'canonical DISCADMIN shell must load the activity panel');
activity_assert(str_contains($ui, 'ADMIN ACTIVITY'), 'Dashboard UI must expose Admin Activity');
activity_assert(str_contains($ui, 'Append-only audit history'), 'UI must explain append-only behavior');
activity_assert(str_contains($ui, 'Restore/revert actions are intentionally not available'), 'v1 must not offer automatic restore');
activity_assert(str_contains($ui, '/api/admin-activity.php'), 'UI must use the protected read-only activity endpoint');

echo "BRVTAL Admin Activity contract tests passed.\n";
