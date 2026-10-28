<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_translation_deepl_page_trial.php';
require_once __DIR__ . '/../config/public_routes.php';

final class BrvtalTrialFakeAdapter implements BrvtalPublicTranslationAdapter
{
    public int $calls = 0;
    public bool $fail = false;
    public ?string $error = null;
    public function version(): string { return 'deepl-fake-page-v1'; }
    public function translate(string $source, string $sourceLocale, string $targetLocale): string
    {
        ++$this->calls;
        if ($this->fail) throw new RuntimeException('fake-unavailable');
        if ($this->error !== null) throw new RuntimeException($this->error);
        return 'English ' . $source;
    }
}

function trialEnv(array $values): Closure
{
    return static fn(string $key): ?string => $values[$key] ?? null;
}
function trialPage(string $slug = 'test-page'): array
{
    return [
        'entity' => [
            'route_type' => 'pages', 'id' => 17, 'slug' => $slug,
            'status' => 'published', 'locale' => 'es', 'visibility' => 'public',
            'trusted' => true, 'title' => 'Hola',
            'description' => 'Pagina de prueba',
            'seo_title' => '', 'seo_description' => '',
        ],
        'degraded' => false, 'record' => ['body_html' => '<p>Español sin traducir</p>'],
    ];
}
function trialScenario(string $name): array
{
    $adapter = new BrvtalTrialFakeAdapter();
    $reads = 0;
    $writes = 0;
    $cacheRead = static function (array $identity) use (&$reads): ?string { ++$reads; return null; };
    $cacheWrite = static function (array $identity, string $text) use (&$writes): void { ++$writes; };
    $on = [
        'BRVTAL_DEEPL_ENABLED' => '1',
        'BRVTAL_DEEPL_TRIAL_OPERATIONAL_PERMIT' => '1',
        'BRVTAL_DEEPL_TRIAL_PAGE_SLUG' => 'test-page',
        'DEEPL_AUTH_KEY' => 'fixture-test-key',
    ];
    $page = trialPage();
    if ($name === 'disabled') {
        $cases = [
            'off' => [],
            'missing_key' => array_diff_key($on, ['DEEPL_AUTH_KEY' => true]),
            'missing_slug' => array_diff_key($on, ['BRVTAL_DEEPL_TRIAL_PAGE_SLUG' => true]),
            'missing_operational_permit' => array_diff_key($on, ['BRVTAL_DEEPL_TRIAL_OPERATIONAL_PERMIT' => true]),
            'wrong_slug' => array_replace($on, ['BRVTAL_DEEPL_TRIAL_PAGE_SLUG' => 'other-page']),
        ];
        $ok = [];
        foreach ($cases as $key => $env) {
            $ok[$key] = brvtalDeepLOnePageTrialProject(
                $page, 'en', 'pages', 'test-page', trialEnv($env), $adapter,
                $cacheRead, $cacheWrite, true, true
            ) === $page;
        }
        return ['ok' => $ok, 'calls' => $adapter->calls, 'reads' => $reads, 'writes' => $writes];
    }
    if ($name === 'scope') {
        $cases = [
            'correct' => [$page, 'en', 'pages', 'test-page', true, true],
            'spanish' => [$page, 'es', 'pages', 'test-page', true, true],
            'other_family' => [$page, 'en', 'artists', 'test-page', true, true],
            'other_slug' => [trialPage('other-page'), 'en', 'pages', 'other-page', true, true],
            'wrong_source_locale' => [array_replace_recursive($page, ['entity' => ['locale' => 'en']]), 'en', 'pages', 'test-page', true, true],
            'draft' => [array_replace_recursive($page, ['entity' => ['status' => 'draft']]), 'en', 'pages', 'test-page', true, true],
            'private' => [array_replace_recursive($page, ['entity' => ['private' => true]]), 'en', 'pages', 'test-page', true, true],
            'untrusted' => [$page, 'en', 'pages', 'test-page', true, false],
            'degraded' => [array_replace($page, ['degraded' => true]), 'en', 'pages', 'test-page', true, true],
        ];
        $results = [];
        foreach ($cases as $name => [$record,$locale,$type,$slug,$isPublic,$trusted]) {
            $out = brvtalDeepLOnePageTrialProject(
                $record, $locale, $type, $slug, trialEnv($on), $adapter,
                $cacheRead, $cacheWrite, $isPublic, $trusted
            );
            $results[$name] = [
                'translated' => ($out['entity']['title'] ?? '') !== ($record['entity']['title'] ?? ''),
                'body_unchanged' => ($out['record'] ?? null) === ($record['record'] ?? null),
            ];
        }
        return ['results' => $results, 'calls' => $adapter->calls];
    }
    if ($name === 'failclosed') {
        $variants = [
            'email' => array_replace_recursive($page, ['entity' => ['description' => 'mail@example.test']]),
            'phone' => array_replace_recursive($page, ['entity' => ['description' => '3214567890']]),
            'html' => array_replace_recursive($page, ['entity' => ['description' => '<b>Texto</b>']]),
            'link' => array_replace_recursive($page, ['entity' => ['description' => 'https://example.test']]),
        ];
        $result = [];
        foreach ($variants as $key => $variant) {
            $out = brvtalDeepLOnePageTrialProject(
                $variant, 'en', 'pages', 'test-page', trialEnv($on), $adapter,
                $cacheRead, $cacheWrite, true, true
            );
            $result[$key] = $out === $variant;
        }
        $zeroCalls = $adapter->calls;
        $adapter->fail = true;
        $out = brvtalDeepLOnePageTrialProject(
            $page, 'en', 'pages', 'test-page', trialEnv($on), $adapter,
            $cacheRead, $cacheWrite, true, true
        );
        $adapter->fail = false;
        $adapter->error = 'deepl_budget_exhausted';
        $budgetFallback = brvtalDeepLOnePageTrialProject(
            $page, 'en', 'pages', 'test-page', trialEnv($on), $adapter,
            $cacheRead, $cacheWrite, true, true
        );
        return ['rejects' => $result, 'before_transport_failure' => $zeroCalls,
            'failure_fallback' => $out === $page,
            'budget_failure_fallback' => $budgetFallback === $page,
            'cache_writes' => $writes];
    }
    if ($name === 'visibility') {
        $off = trialEnv([]);
        $valid = trialEnv($on);
        $wrong = trialEnv(array_replace($on, ['BRVTAL_DEEPL_TRIAL_PAGE_SLUG' => 'other-page']));
        $noKey = trialEnv(array_diff_key($on, ['DEEPL_AUTH_KEY' => true]));
        $noPermit = trialEnv(array_replace($on, ['BRVTAL_DEEPL_TRIAL_OPERATIONAL_PERMIT' => '0']));
        $english = ['status' => 'published', 'locale' => 'en', 'slug' => 'test-page'];
        $spanish = ['status' => 'published', 'locale' => 'es', 'slug' => 'test-page',
            'visibility' => 'public', 'trusted' => true];
        $default = brvtal_public_content_definitions($off)['pages'];
        $enabled = brvtal_public_content_definitions($valid)['pages'];
        return [
            'default_route_unchanged' => $default['where'] === "status='published' AND locale='en'"
                && $default['parameters'] === [],
            'single_bound_spanish_source' => str_contains($enabled['where'], "locale='es' AND slug=?")
                && $enabled['parameters'] === ['test-page', 'test-page'],
            'english_unchanged' => brvtal_public_page_is_visible($english, $off),
            'spanish_off' => !brvtal_public_page_is_visible($spanish, $off),
            'chosen_spanish_only' => brvtal_public_page_is_visible($spanish, $valid),
            'different_slug' => !brvtal_public_page_is_visible(
                array_replace($spanish, ['slug' => 'other-page']), $valid
            ),
            'wrong_locale' => !brvtal_public_page_is_visible(
                array_replace($spanish, ['locale' => 'fr']), $valid
            ),
            'draft' => !brvtal_public_page_is_visible(
                array_replace($spanish, ['status' => 'draft']), $valid
            ),
            'private' => !brvtal_public_page_is_visible(
                array_replace($spanish, ['visibility' => 'private']), $valid
            ),
            'untrusted' => !brvtal_public_page_is_visible(
                array_replace($spanish, ['trusted' => false]), $valid
            ),
            'wrong_slug_gate' => !brvtal_public_page_is_visible($spanish, $wrong),
            'missing_key_gate' => !brvtal_public_page_is_visible($spanish, $noKey),
            'missing_permit_gate' => !brvtal_public_page_is_visible($spanish, $noPermit),
        ];
    }
    if ($name === 'receipt') {
        $fake = array_replace($on, ['BRVTAL_DEEPL_TRIAL_OPERATIONAL_PERMIT' => '0']);
        $fake['D043_RECEIPT'] = 'VALIDATED_IN_PRODUCTION';
        $fake['D043_PENDING'] = '[]';
        $result = brvtalDeepLOnePageTrialProject(
            $page, 'en', 'pages', 'test-page', trialEnv($fake), $adapter,
            $cacheRead, $cacheWrite, true, true
        );
        return ['claimed_receipt_not_authority' => $result === $page,
            'calls' => $adapter->calls, 'cache_writes' => $writes];
    }
    throw new RuntimeException('Unknown trial scenario');
}
echo json_encode(trialScenario($argv[1] ?? ''), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
