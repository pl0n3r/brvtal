<?php
declare(strict_types=1);

/** Fail when Content Core health routing drifts back to value-based attribute checks. */
function content_core_health_presence_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "CONTENT CORE HEALTH PRESENCE CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$navigation = (string) file_get_contents(__DIR__ . '/../discadmin/content-core-nav.js');
$browserRegression = (string) file_get_contents(__DIR__ . '/e2e/discadmin-content-health-navigation.spec.mjs');

content_core_health_presence_expect(
    preg_match("/['\"]healthOpen['\"]\\s+in\\s+button\\.dataset/", $navigation) === 1,
    'Health routing must classify data-health-open by dataset key presence.'
);
content_core_health_presence_expect(
    preg_match("/button\\.hasAttribute\\s*\\(\\s*['\"]data-health-open['\"]\\s*\\)/", $navigation) !== 1,
    'The legacy hasAttribute data-health-open check must not return.'
);
content_core_health_presence_expect(
    str_contains($browserRegression, "setAttribute('data-health-open', '')"),
    'Browser coverage must preserve the present-but-empty data-health-open case.'
);

echo "Content Core health attribute presence contract passed.\n";
