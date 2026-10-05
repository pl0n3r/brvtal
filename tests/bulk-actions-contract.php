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
$catalog = (string)file_get_contents($root . '/api/bulk-catalog.php');
$library = (string)file_get_contents($root . '/api/bulk-actions-lib.php');
$shell = (string)file_get_contents($root . '/discadmin/index.php');
$ui = (string)file_get_contents($root . '/discadmin/bulk-actions.js');

bulk_assert(str_contains($endpoint, 'brvtal_admin_require();'), 'endpoint must require an authenticated admin session');
bulk_assert(str_contains($endpoint, 'brvtal_admin_require_csrf();'), 'endpoint must require CSRF protection');
bulk_assert(str_contains($endpoint, "'POST'"), 'endpoint must accept POST only');
bulk_assert(str_contains($catalog, 'brvtal_admin_require();'), 'catalog endpoint must require an authenticated admin session');
bulk_assert(str_contains($catalog, "'GET'"), 'catalog endpoint must accept GET only');
bulk_assert(str_contains($catalog, "['Allow'=>'GET', 'Cache-Control'=>'no-store']"), 'catalog endpoint must be explicit GET/no-store');
bulk_assert(!str_contains($catalog, 'brvtal_admin_require_csrf'), 'catalog GET must not require mutation CSRF');
bulk_assert(!str_contains($catalog, '$_POST'), 'catalog endpoint must remain read-only');
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
bulk_assert(str_contains($shell, '/discadmin/bulk-actions.js'), 'canonical shell must load Bulk Actions');
bulk_assert(str_contains($ui, 'NO BULK DELETE'), 'UI must communicate that bulk deletion is unavailable');
bulk_assert(str_contains($ui, "window.confirm(`Set"), 'UI must require confirmation before mutation');
bulk_assert(str_contains($ui, "'X-CSRF-Token':token"), 'UI must send the CSRF token');
bulk_assert(str_contains($ui, "const CATALOG_ENDPOINT = '/api/bulk-catalog.php';"), 'UI must read from the bounded catalog endpoint');
bulk_assert(!str_contains($ui, '/api/index.php/events'), 'UI must not download the full Events collection');
bulk_assert(!str_contains($ui, '/api/releases.php'), 'UI must not download the full Releases collection');
bulk_assert(str_contains($ui, 'state.cursorStack[index] = state.nextCursor;'), 'UI must navigate using server cursors');
bulk_assert(str_contains($ui, 'const MAX_SELECTED = 100;'), 'UI must preserve the global 100 selection limit');
bulk_assert(str_contains($ui, 'button.disabled = !state.ready'), 'UI must fail closed while catalog evidence is unavailable');

if (getenv('BRVTAL_INTEGRATION_TESTS') === '1') {
    require_once $root . '/api/bulk-actions-lib.php';
    $host = (string)getenv('BRVTAL_TEST_DB_HOST');
    $port = (string)(getenv('BRVTAL_TEST_DB_PORT') ?: '3306');
    $name = (string)getenv('BRVTAL_TEST_DB_NAME');
    $user = (string)getenv('BRVTAL_TEST_DB_USER');
    $pass = (string)getenv('BRVTAL_TEST_DB_PASS');
    bulk_assert($host !== '' && $name !== '' && $user !== '', 'MariaDB fixture credentials must be configured');

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS pages');
    $pdo->exec(
        "CREATE TEMPORARY TABLE pages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(220) NOT NULL,
            slug VARCHAR(190) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'draft',
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    try {
        $prefix = 'bulk-ac02-' . bin2hex(random_bytes(6));
        $needle = 'needle-' . bin2hex(random_bytes(6));
        $needleId = 0;
        $insert = $pdo->prepare(
            'INSERT INTO pages (title,slug,status,updated_at) VALUES (?,?,?,NOW())'
        );
        for ($i = 1; $i <= 520; $i++) {
            $title = $prefix . ' item ' . str_pad((string)$i, 4, '0', STR_PAD_LEFT);
            if ($i === 510) {
                $title .= ' ' . $needle;
            }
            $insert->execute([$title, $prefix . '-' . $i, 'draft']);
            if ($i === 510) {
                $needleId = (int)$pdo->lastInsertId();
            }
        }

        $cursor = '';
        $ids = [];
        $pageCount = 0;
        do {
            $query = brvtalBulkCatalogNormalizeQuery([
                'resource' => 'pages',
                'q' => $prefix,
                'cursor' => $cursor,
                'limit' => '50',
            ]);
            $page = brvtalBulkCatalogFetch($pdo, $query);
            bulk_assert(($page['pagination']['total'] ?? null) === 520, 'snapshot total must stay 520');
            bulk_assert(
                ($page['pagination']['snapshot_complete'] ?? null)
                    === !($page['pagination']['has_more'] ?? false),
                'snapshot completeness must match whether continuation remains'
            );
            foreach ($page['items'] as $item) {
                $ids[] = (int)$item['id'];
            }
            $cursor = (string)($page['pagination']['next_cursor'] ?? '');
            $pageCount++;
            bulk_assert($pageCount <= 11, 'cursor must terminate within eleven pages');
        } while ($cursor !== '');

        bulk_assert($pageCount === 11, '520 rows at limit 50 must require eleven pages');
        bulk_assert(count($ids) === 520, 'cursor pagination must return all 520 rows');
        bulk_assert(count(array_unique($ids)) === 520, 'cursor pagination must not duplicate IDs');
        $sorted = $ids;
        sort($sorted, SORT_NUMERIC);
        bulk_assert($ids === $sorted, 'cursor pagination must preserve stable ascending order');

        $search = brvtalBulkCatalogFetch($pdo, brvtalBulkCatalogNormalizeQuery([
            'resource' => 'pages',
            'q' => strtolower($needle),
            'cursor' => '',
            'limit' => '50',
        ]));
        bulk_assert(($search['pagination']['total'] ?? null) === 1, 'search must find one deep-page row');
        bulk_assert((int)($search['items'][0]['id'] ?? 0) === $needleId, 'search must find row 510+');

        $first = brvtalBulkCatalogFetch($pdo, brvtalBulkCatalogNormalizeQuery([
            'resource' => 'pages',
            'q' => $prefix,
            'cursor' => '',
            'limit' => '50',
        ]));
        $staleCursor = (string)($first['pagination']['next_cursor'] ?? '');
        bulk_assert($staleCursor !== '', 'first page must expose a continuation cursor');
        $touch = $pdo->prepare(
            'UPDATE pages SET updated_at=DATE_ADD(updated_at, INTERVAL 2 DAY) WHERE id=?'
        );
        $touch->execute([$needleId]);

        $staleRejected = false;
        try {
            $staleQuery = brvtalBulkCatalogNormalizeQuery([
                'resource' => 'pages',
                'q' => $prefix,
                'cursor' => $staleCursor,
                'limit' => '50',
            ]);
            brvtalBulkCatalogFetch($pdo, $staleQuery);
        } catch (InvalidArgumentException $e) {
            $staleRejected = $e->getMessage() === 'STALE_BULK_CURSOR';
        }
        bulk_assert($staleRejected, 'changed snapshot must reject the continuation cursor');
        echo "BRVTAL Bulk Catalog dynamic pagination passed.\n";
    } finally {
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS pages');
    }
}

echo "BRVTAL Bulk Actions contract tests passed.\n";
