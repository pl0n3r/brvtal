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

fwrite(STDOUT, "Admin read-plan contract passed.\n");
