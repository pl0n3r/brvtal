<?php
declare(strict_types=1);

function blog_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "BLOG CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$migration = (string)file_get_contents(__DIR__ . '/../database/migration_blog_01.sql');
foreach (['blog_posts','blog_tags','blog_post_tags','blog_post_relations'] as $table) {
    blog_assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS ' . $table), "migration must create {$table}");
}
blog_assert(str_contains($migration, "ENUM('draft','published','archived')"), 'blog lifecycle must include draft/published/archived');
blog_assert(str_contains($migration, "ENUM('event','artist','set','release')"), 'blog relations must support core content types');

$api = (string)file_get_contents(__DIR__ . '/../api/blog.php');
$relationsHelper = (string)file_get_contents(__DIR__ . '/../api/blog-relations.php');
blog_assert(str_contains($api, 'brvtal_admin_require();'), 'blog API must require admin authentication');
blog_assert(str_contains($api, 'brvtal_admin_require_csrf();'), 'blog mutations must require CSRF');
blog_assert(str_contains($api, 'BLOG_SCHEMA_MISSING'), 'blog API must fail explicitly before migration');
blog_assert(str_contains($api, 'brvtal_blog_sync_tags'), 'blog API must persist tags');
blog_assert(str_contains($api, 'brvtal_blog_sync_relations'), 'blog API must persist related content');
blog_assert(str_contains($api, "['draft','published','archived']"), 'blog API must validate lifecycle status');
blog_assert(str_contains($api, "require_once __DIR__ . '/blog-relations.php';"), 'blog API must load the relation integrity boundary');
blog_assert(str_contains($api, "brvtal_blog_relation_id(\$relation['related_id'] ?? null)"), 'blog payload must validate raw relation IDs before persistence');
blog_assert(str_contains($api, 'brvtal_blog_lock_relation_targets($pdo, $data[\'relations\']);'), 'blog API must validate and lock relation targets inside the mutation transaction');
foreach (['event' => 'events','artist' => 'artists','set' => 'sets_media','release' => 'releases'] as $type => $table) {
    blog_assert(str_contains($relationsHelper, "'{$type}' => '{$table}'"), "relation helper must map {$type} to {$table}");
}
blog_assert(str_contains($relationsHelper, 'FILTER_VALIDATE_INT'), 'relation IDs must be validated without lossy scalar coercion');
blog_assert(str_contains($relationsHelper, 'is_bool($value) || is_float($value)'), 'boolean and fractional relation IDs must be rejected explicitly');
blog_assert(str_contains($relationsHelper, 'FOR UPDATE'), 'relation targets must remain locked through Blog commit');
blog_assert(str_contains($relationsHelper, 'BLOG_RELATION_NOT_FOUND'), 'missing Blog relation targets must fail deterministically');

$fragment = (string)file_get_contents(__DIR__ . '/../discadmin/blog.php');
blog_assert(str_contains($fragment, 'data-admin-module="blog"'), 'blog must render as canonical shell module');
blog_assert(str_contains($fragment, 'X_BRVTAL_ADMIN_FRAGMENT'), 'direct blog fragment requests must redirect to DISCADMIN shell');

$controller = (string)file_get_contents(__DIR__ . '/../discadmin/blog.js');
blog_assert(str_contains($controller, "const endpoint = '/api/blog.php'"), 'blog UI must use protected blog API');
blog_assert(str_contains($controller, 'BRVTALMediaLibrary?.openPicker'), 'blog cover must use Media Library picker');
blog_assert(str_contains($controller, 'data-blog-related-type'), 'blog editor must support related content');
blog_assert(
    str_contains($controller, "method:id ? 'PUT' : 'POST'")
        || str_contains($controller, "method:id?'PUT':'POST'"),
    'blog editor must create and update posts'
);
blog_assert(!str_contains($controller, 'id="blog_tags"'), 'ordinary blog editor must not expose manual taxonomy controls');
blog_assert(!str_contains($controller, "split(',')"), 'ordinary blog saves must not synthesize or clear taxonomy from a manual comma field');

$adminModules = (string)file_get_contents(__DIR__ . '/../discadmin/admin-modules.js');
blog_assert(str_contains($adminModules, "blog: {url:'/discadmin/blog.php'"), 'canonical shell loader must register blog module');
blog_assert(str_contains($adminModules, "['blog', ['media','blog']]"), 'Blog must remain registered as a dynamic module dependency');
blog_assert(str_contains($adminModules, 'sectionDependencies.has(section)'), 'canonical navigation must route dynamic modules through one workspace boundary');
blog_assert(str_contains($adminModules, 'dataset.adminNav = section'), 'dynamic navigation must support blog');

$adminCss = (string)file_get_contents(__DIR__ . '/../discadmin/admin-modules.css');
blog_assert(str_contains($adminCss, 'data-admin-nav="blog"'), 'blog must stay in the content navigation group');

$media = (string)file_get_contents(__DIR__ . '/../config/media.php');
blog_assert(str_contains($media, "'table' => 'blog_posts'"), 'Media Library must detect Blog cover usage');
blog_assert(str_contains($media, "'label' => 'BLOG'"), 'Blog media references must be identifiable in delete protection');

$publicApi = (string)file_get_contents(__DIR__ . '/../api/public.php');
blog_assert(str_contains($publicApi, 'brvtal_public_blog'), 'public API must expose published blog posts');
blog_assert(str_contains($publicApi, "'blog' => " . '$blog'), 'public payload must contain blog');
blog_assert(str_contains($publicApi, "FROM blog_posts"), 'public blog must read blog_posts');
blog_assert(str_contains($publicApi, "WHERE status='published'"), 'public blog must be publication-filtered');
blog_assert(str_contains($publicApi, 'blog_post_tags'), 'public blog must expose taxonomy');
blog_assert(str_contains($publicApi, 'blog_post_relations'), 'public blog must expose related content');
blog_assert(str_contains($publicApi, 'brvtal_public_table_exists'), 'public API must remain backward-compatible before migration');

echo "BRVTAL Blog contract tests passed.\n";
