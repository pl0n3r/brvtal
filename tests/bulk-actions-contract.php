<?php
declare(strict_types=1);

function bulk_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "BULK ACTIONS CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$endpoint = (string)file_get_contents($root . '/api/bulk-actions.php');
$library = (string)file_get_contents($root . '/api/bulk-actions-lib.php');
$shell = (string)file_get_contents($root . '/discadmin/index.php');
$ui = (string)file_get_contents($root . '/discadmin/bulk-actions.js');

bulk_assert(str_contains($endpoint, 'brvtal_admin_require();'), 'endpoint must require an authenticated admin session');
bulk_assert(str_contains($endpoint, 'brvtal_admin_require_csrf();'), 'endpoint must require CSRF protection');
bulk_assert(str_contains($endpoint, "'POST'"), 'endpoint must accept POST only');
bulk_assert(str_contains($library, "'events' =>"), 'events must be explicitly allowlisted');
bulk_assert(str_contains($library, "'artists' =>"), 'artists must be explicitly allowlisted');
bulk_assert(str_contains($library, "'sets' =>"), 'sets must be explicitly allowlisted');
bulk_assert(str_contains($library, "'pages' =>"), 'pages must be explicitly allowlisted');
bulk_assert(str_contains($library, "'releases' =>"), 'releases must be explicitly allowlisted');
bulk_assert(str_contains($library, "'blog' =>"), 'blog must be explicitly allowlisted');
bulk_assert(!str_contains($library, "'media' =>"), 'media must stay outside Bulk Actions v1');
bulk_assert(str_contains($library, 'count($rawIds) > 100'), 'request must enforce the 100 item safety limit');
bulk_assert(str_contains($library, 'FOR UPDATE'), 'bulk status changes must lock selected rows');
bulk_assert(str_contains($library, 'rollBack()'), 'bulk status changes must rollback on failure');
bulk_assert(!str_contains($library, 'DELETE FROM'), 'Bulk Actions v1 must not expose bulk deletion');
bulk_assert(str_contains($library, "require_once __DIR__ . '/../config/event_lifecycle.php';"), 'Bulk Actions must reuse the canonical Event lifecycle policy');
bulk_assert(str_contains($library, 'brvtal_event_lifecycle_patch($row'), 'bulk Event updates must derive lifecycle timestamps from each locked row');
bulk_assert(str_contains($library, 'published_at,cancelled_at,finished_at'), 'bulk Event locks must hydrate lifecycle timestamps');
bulk_assert(str_contains($library, "in_array(\$resource, ['releases','blog'], true)"), 'Blog and Releases must use their publication timestamp lifecycle in bulk');
bulk_assert(str_contains($library, "COALESCE(published_at,CURRENT_TIMESTAMP)"), 'bulk publishing Blog/Releases must stamp published_at exactly once');
bulk_assert(str_contains($shell, '/discadmin/bulk-actions.js'), 'canonical shell must load Bulk Actions');
bulk_assert(str_contains($ui, 'NO BULK DELETE'), 'UI must communicate that bulk deletion is unavailable');
bulk_assert(str_contains($ui, "window.confirm(`Set"), 'UI must require confirmation before mutation');
bulk_assert(str_contains($ui, "'X-CSRF-Token':token"), 'UI must send the CSRF token');

echo "BRVTAL Bulk Actions contract tests passed.\n";
