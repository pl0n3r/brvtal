<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/content_ordering.php';

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "Content ordering contract failed: {$message}\n"); exit(1); }
};

$resources = brvtal_content_order_resources();
$expect(array_keys($resources) === ['artists','sets','releases','blog'], 'canonical resources must be explicit');
$expect($resources['sets']['table'] === 'sets_media', 'Sets must map to sets_media');
$expect($resources['blog']['activity'] === 'blog', 'Blog activity resource must stay canonical');
$expect(brvtal_content_order_resource(' RELEASES ') === $resources['releases'], 'resource lookup must normalize');
$expect(brvtal_content_order_resource('events') === null, 'non-approved resources must fail closed');
$expect(brvtal_content_order_ids([3,'2',1]) === [3,2,1], 'valid IDs preserve submitted order');
foreach ([[],[1,1],[0,1],[-1,2],['1x',2],[1.5,2]] as $invalid) {
    $failed=false; try { brvtal_content_order_ids($invalid); } catch (InvalidArgumentException $e) { $failed=true; }
    $expect($failed, 'invalid or duplicate IDs must fail');
}
$oversized = range(1,501);
$failed=false; try { brvtal_content_order_ids($oversized); } catch (InvalidArgumentException $e) { $failed=true; }
$expect($failed, 'oversized order payload must fail');
$expect(brvtal_content_order_matches([3,1,2],[1,2,3]), 'exact ID sets may arrive in different order');
$expect(!brvtal_content_order_matches([1,2],[1,2,3]), 'partial set must be stale');
$expect(!brvtal_content_order_matches([1,2,4],[1,2,3]), 'foreign ID must be stale');

$endpoint=(string)file_get_contents(__DIR__ . '/../api/reorder.php');
$core=(string)file_get_contents(__DIR__ . '/../discadmin/index-core.php');
$modules=(string)file_get_contents(__DIR__ . '/../discadmin/admin-modules.js');
$releases=(string)file_get_contents(__DIR__ . '/../discadmin/releases.js');
$blog=(string)file_get_contents(__DIR__ . '/../discadmin/blog.js');
$public=(string)file_get_contents(__DIR__ . '/../api/public.php');
$expect(str_contains($endpoint,'brvtal_admin_require_csrf'), 'reorder must require CSRF');
$expect(str_contains($endpoint,'FOR UPDATE'), 'reorder must lock collection');
$expect(str_contains($endpoint,'ORDER_STALE'), 'stale writes must fail explicitly');
$expect(str_contains($endpoint,"$previousIds !== $currentIds"), 'reorder must reject a concurrent order change even when the ID set is unchanged');
$expect(str_contains($endpoint,'beginTransaction') && str_contains($endpoint,'rollBack'), 'reorder must be transactional');
$expect(str_contains($endpoint,'SET sort_order=? WHERE id=?'), 'reorder must normalize positions');
$expect(str_contains($core,'Display order is managed visually from the Artists list.'), 'Artist form must explain visual order');
$expect(str_contains($core,'Display order is managed visually from the Sets list.'), 'Set form must explain visual order');
$expect(str_contains($core,'data-order-resource="${state.section}"'), 'core list must expose ordering contract');
$expect(str_contains($modules,'visualOrderValue'), 'create/edit must preserve hidden order');
$expect(!str_contains($releases,'id="release_sort_order"'), 'Release numeric Sort Order must be removed');
$expect(!str_contains($blog,'id="blog_sort_order"'), 'Blog numeric Sort Order must be removed');
$expect(str_contains($public,"ORDER BY sort_order ASC, featured DESC, COALESCE(release_date"), 'public Releases must honor canonical order first');
$expect(str_contains($public,"ORDER BY sort_order ASC, featured DESC, COALESCE(published_at"), 'public Blog must honor canonical order first');
fwrite(STDOUT,"Content ordering contract passed.\n");
