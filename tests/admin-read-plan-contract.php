<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/admin-read-plan.php';

function admin_read_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

admin_read_expect(
    brvtalAdminCollectionReadPlan('settings', []) === null,
    'Legacy Settings collection reads must remain unchanged when no key is requested'
);

$settingPlan = brvtalAdminCollectionReadPlan('settings', ['key' => BRVTAL_ADMIN_HERO_SETTING_KEY]);
admin_read_expect(is_array($settingPlan), 'Scoped Settings reads must produce a read plan');
admin_read_expect(
    ($settingPlan['params'] ?? []) === [BRVTAL_ADMIN_HERO_SETTING_KEY],
    'Scoped Settings reads must bind only the requested canonical key'
);
admin_read_expect(
    str_contains((string)($settingPlan['sql'] ?? ''), 'setting_key,setting_value,is_json'),
    'Scoped Settings reads must project only the fields needed by Banners'
);
admin_read_expect(
    !str_contains((string)($settingPlan['sql'] ?? ''), 'SELECT *'),
    'Scoped Settings reads must not load the full Settings row'
);

$protectedPlan = brvtalAdminCollectionReadPlan(
    'settings',
    ['key' => BRVTAL_ADMIN_PROTECTED_SETTING_KEY]
);
admin_read_expect(
    ($protectedPlan['error'] ?? null) === 'PROTECTED_SETTING'
        && ($protectedPlan['status'] ?? null) === 403,
    'Protected Settings must fail closed even through scoped reads'
);

$unknownPlan = brvtalAdminCollectionReadPlan('settings', ['key' => 'site.title']);
admin_read_expect(
    ($unknownPlan['error'] ?? null) === 'SETTING_NOT_ALLOWED'
        && ($unknownPlan['status'] ?? null) === 422
        && ($unknownPlan['sql'] ?? null) === null,
    'Scoped Settings reads must allow only the canonical Hero setting'
);

$heroCaseVariantPlan = brvtalAdminCollectionReadPlan('settings', ['key' => 'Home.Hero.Slider']);
admin_read_expect(
    ($heroCaseVariantPlan['error'] ?? null) === 'SETTING_NOT_ALLOWED'
        && ($heroCaseVariantPlan['sql'] ?? null) === null,
    'Case variants of the Hero setting must not reach case-insensitive SQL lookup'
);

$protectedCaseVariantPlan = brvtalAdminCollectionReadPlan(
    'settings',
    ['key' => 'Security.TOTP_Encryption_Key']
);
admin_read_expect(
    ($protectedCaseVariantPlan['error'] ?? null) === 'SETTING_NOT_ALLOWED'
        && ($protectedCaseVariantPlan['sql'] ?? null) === null,
    'Protected-key case variants must fail closed before SQL execution'
);

$whitespaceVariantPlan = brvtalAdminCollectionReadPlan('settings', ['key' => ' home.hero.slider ']);
admin_read_expect(
    ($whitespaceVariantPlan['error'] ?? null) === 'KEY_REQUIRED'
        && ($whitespaceVariantPlan['sql'] ?? null) === null,
    'Scoped Settings reads must require the exact canonical key spelling'
);

$emptyPlan = brvtalAdminCollectionReadPlan('settings', ['key' => '']);
admin_read_expect(
    ($emptyPlan['error'] ?? null) === 'KEY_REQUIRED'
        && ($emptyPlan['status'] ?? null) === 422,
    'Empty scoped Settings keys must be rejected'
);

$invalidShapePlan = brvtalAdminCollectionReadPlan('settings', ['key' => ['home.hero.slider']]);
admin_read_expect(
    ($invalidShapePlan['error'] ?? null) === 'KEY_REQUIRED'
        && ($invalidShapePlan['status'] ?? null) === 422,
    'Array-shaped Settings keys must fail closed without PHP string-conversion warnings'
);

$mediaPlan = brvtalAdminCollectionReadPlan('media', ['view' => BRVTAL_ADMIN_HERO_MEDIA_VIEW]);
admin_read_expect(is_array($mediaPlan), 'Hero picker Media reads must produce a read plan');
admin_read_expect(
    ($mediaPlan['params'] ?? []) === [],
    'Hero picker Media projection must not depend on user-controlled SQL parameters'
);
admin_read_expect(
    str_contains((string)($mediaPlan['sql'] ?? ''), 'id,type,title,file_path,status'),
    'Hero picker Media reads must project only fields required by Banners'
);
admin_read_expect(
    str_contains((string)($mediaPlan['sql'] ?? ''), "type IN ('image','video')"),
    'Hero picker Media reads must exclude unrelated audio/document records'
);
admin_read_expect(
    str_contains((string)($mediaPlan['sql'] ?? ''), "status='published'"),
    'Hero picker Media reads must expose only published assets'
);
admin_read_expect(
    str_contains((string)($mediaPlan['sql'] ?? ''), 'LIMIT ' . BRVTAL_ADMIN_HERO_MEDIA_LIMIT),
    'Hero picker Media reads must stay bounded on first load'
);
admin_read_expect(
    !str_contains((string)($mediaPlan['sql'] ?? ''), 'SELECT *'),
    'Hero picker Media reads must not fetch full Media rows'
);

admin_read_expect(
    brvtalAdminCollectionReadPlan('media', ['view' => 'unknown']) === null,
    'Unknown Media views must preserve the legacy collection behavior'
);
admin_read_expect(
    brvtalAdminCollectionReadPlan('media', ['view' => ['hero-picker']]) === null,
    'Array-shaped Media views must not activate an optimized SQL plan'
);
admin_read_expect(
    brvtalAdminCollectionReadPlan('events', ['view' => BRVTAL_ADMIN_HERO_MEDIA_VIEW]) === null,
    'Optimized read views must not leak into unrelated resources'
);

admin_read_expect(
    brvtalAdminCollectionPagination('events', []) === null,
    'Internal collection consumers must keep the legacy full response unless pagination is requested explicitly'
);
$pagination = brvtalAdminCollectionPagination('events', ['page'=>'1','page_size'=>'50']);
admin_read_expect(
    is_array($pagination)
        && $pagination['page'] === 1
        && $pagination['page_size'] === BRVTAL_ADMIN_COLLECTION_PAGE_SIZE
        && $pagination['error'] === null,
    'Canonical Admin lists must accept an explicit bounded first page'
);
$customPagination = brvtalAdminCollectionPagination('artists', ['page'=>'3','page_size'=>'25']);
admin_read_expect(
    ($customPagination['page'] ?? null) === 3 && ($customPagination['page_size'] ?? null) === 25,
    'Admin collection pagination must accept bounded positive page parameters'
);
admin_read_expect(
    (brvtalAdminCollectionPagination('events', ['page'=>'0'])['error'] ?? null) === 'INVALID_PAGE',
    'Admin collection pagination must reject zero/negative page numbers'
);
admin_read_expect(
    (brvtalAdminCollectionPagination('events', ['page_size'=>'101'])['error'] ?? null) === 'INVALID_PAGE_SIZE',
    'Admin collection pagination must reject oversized pages'
);
$searchPagination = brvtalAdminCollectionPagination('events', ['page'=>'1','q'=>'Genesis_100%']);
admin_read_expect(
    ($searchPagination['query'] ?? null) === 'Genesis_100%'
        && str_contains((string)($searchPagination['where_sql'] ?? ''), 'title')
        && count($searchPagination['params'] ?? []) === 4
        && str_contains((string)($searchPagination['where_sql'] ?? ''), "ESCAPE '!'")
        && str_contains((string)($searchPagination['params'][0] ?? ''), '!_')
        && str_contains((string)($searchPagination['params'][0] ?? ''), '!%'),
    'Canonical list search must be server-side, resource-scoped and escape LIKE wildcards'
);
admin_read_expect(
    (brvtalAdminCollectionPagination('events', ['q'=>[]])['error'] ?? null) === 'INVALID_QUERY',
    'Array-shaped list search must fail closed'
);
admin_read_expect(
    brvtalAdminCollectionPagination('settings', []) === null,
    'Settings must preserve their specialized unpaginated configuration response'
);
$paginationMeta = brvtalAdminCollectionPaginationMeta(8, 50, 112);
admin_read_expect(
    $paginationMeta['page'] === 3
        && $paginationMeta['pages'] === 3
        && $paginationMeta['offset'] === 100
        && $paginationMeta['has_previous'] === true
        && $paginationMeta['has_next'] === false,
    'Pagination metadata must clamp beyond-last-page requests to the final stable page'
);

fwrite(STDOUT, "Admin read-plan contract passed.\n");
