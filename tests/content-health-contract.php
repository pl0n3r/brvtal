<?php
declare(strict_types=1);

function health_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "CONTENT HEALTH CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$api = (string)file_get_contents(__DIR__ . '/../api/content-health.php');
health_assert(str_contains($api, 'brvtal_admin_require();'), 'Content Health API must require admin authentication');
health_assert(str_contains($api, "REQUEST_METHOD") && str_contains($api, "'GET'"), 'Content Health API must be read-only GET');
health_assert(str_contains($api, 'METHOD_NOT_ALLOWED'), 'non-GET Content Health requests must be rejected');
health_assert(str_contains($api, 'brvtalSchemaTableExists($pdo, $table)'), 'optional modules must be checked through the shared schema catalog before querying');
health_assert(!str_contains($api, 'information_schema.TABLES'), 'Content Health must not probe information_schema once per optional module');
health_assert(str_contains($api, 'SHOW COLUMNS FROM `events`'), 'Content Health must introspect optional events columns on the base schema');
health_assert(str_contains($api, 'isset($available['), 'Content Health must omit optional events columns absent from the actual schema');
foreach (['events','artists','sets','releases','pages','blog'] as $type) {
    health_assert(str_contains($api, "'{$type}'"), "Content Health must include {$type}");
}
health_assert(str_contains($api, "'score'"), 'Content Health must calculate a score');
health_assert(str_contains($api, "'issues'"), 'Content Health must return actionable issues');
health_assert(str_contains($api, "'seo_supported'"), 'Content Health must distinguish types that persist SEO metadata');
health_assert(str_contains($api, 'brvtalMediaImageReferenceState'), 'Content Health must resolve visual references instead of trusting non-empty strings');
health_assert(str_contains($api, 'Broken primary visual'), 'Content Health must distinguish a broken visual reference from a missing visual');
health_assert(str_contains($api, "'image_reference_kind'"), 'Content Health must expose visual-reference diagnostics');
health_assert(str_contains($api, "'empty_visuals'"), 'Content Health must count truly empty visual fields separately');
health_assert(str_contains($api, "'broken_visuals'"), 'Content Health must count broken visual references separately');
health_assert(!preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE)\s+(?:INTO\s+|FROM\s+)?[`a-z_]/i', $api), 'Content Health API must not mutate database records');

$controller = (string)file_get_contents(__DIR__ . '/../discadmin/content-health.js');
health_assert(str_contains($controller, "'/api/content-health.php'"), 'Dashboard panel must use Content Health API');
health_assert(str_contains($controller, "state.section !== 'dashboard'"), 'Content Health must stay scoped to Dashboard');
health_assert(str_contains($controller, 'Read-only diagnostics'), 'UI must state that diagnostics do not mutate content');
health_assert(str_contains($controller, 'PUBLIC EMPTY VISUALS'), 'Dashboard must show empty visual debt explicitly');
health_assert(str_contains($controller, 'PUBLIC BROKEN VISUALS'), 'Dashboard must show broken visual debt explicitly');
health_assert(str_contains($controller, 'data-health-open'), 'priority issues must link back to canonical editors');
health_assert(str_contains($controller, 'window.go'), 'editor navigation must use canonical DISCADMIN routing');

$entry = (string)file_get_contents(__DIR__ . '/../discadmin/index.php');
health_assert(str_contains($entry, "require __DIR__ . '/index-core.php';"), 'canonical DISCADMIN shell must remain index-core based');
health_assert(str_contains($entry, '/discadmin/content-health.js'), 'canonical shell must load Content Health enhancement');
health_assert(!str_contains($entry, 'admin-sidebar.php'), 'Content Health must not introduce a parallel sidebar');

echo "BRVTAL Content Health contract tests passed.\n";
