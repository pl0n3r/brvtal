<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_translation_deepl_transport.php';
require_once __DIR__ . '/../config/public_translation_overlay.php';

const DEEPL_REAL_FIXTURE_KEY = 'fixture-key-must-never-be-exposed';

function real_report(array $result): never
{
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

function real_error(callable $operation): string
{
    try {
        $operation();
    } catch (Throwable $error) {
        return $error->getMessage();
    }
    throw new RuntimeException('DEEPL REAL TEST: expected rejection');
}

function real_env(array $values): Closure
{
    return static fn(string $name): ?string => $values[$name] ?? null;
}

$on = ['BRVTAL_DEEPL_ENABLED' => '1', 'DEEPL_AUTH_KEY' => DEEPL_REAL_FIXTURE_KEY];
$budget = tempnam(sys_get_temp_dir(), 'deepl-budget-');
if ($budget === false || !unlink($budget)) {
    throw new RuntimeException('DEEPL REAL TEST: fixture unavailable');
}
$scenario = $argv[1] ?? '';

if ($scenario === 'off') {
    $calls = 0;
    $http = static function () use (&$calls): array { $calls++; return ['status' => 200, 'body' => '{}']; };
    $results = [];
    foreach ([
        'sin_nada' => [],
        'solo_clave' => ['DEEPL_AUTH_KEY' => DEEPL_REAL_FIXTURE_KEY],
        'solo_interruptor' => ['BRVTAL_DEEPL_ENABLED' => '1'],
        'interruptor_en_cero' => ['BRVTAL_DEEPL_ENABLED' => '0', 'DEEPL_AUTH_KEY' => DEEPL_REAL_FIXTURE_KEY],
        'clave_vacia' => ['BRVTAL_DEEPL_ENABLED' => '1', 'DEEPL_AUTH_KEY' => '  '],
    ] as $name => $env) {
        $results[$name] = BrvtalDeepLHttpTransport::adapterFromEnvironment($http, real_env($env), $budget) === null;
    }
    $direct = new BrvtalDeepLHttpTransport($http, real_env([]), $budget);
    $reason = real_error(static fn() => $direct(['text' => ['Hola'], 'source_lang' => 'ES', 'target_lang' => 'EN']));
    real_report(['off' => $results, 'direct_reason' => $reason, 'http_calls' => $calls]);
}

if ($scenario === 'contract') {
    $seen = [];
    $http = static function (string $url, array $headers, string $body, int $timeout) use (&$seen): array {
        $seen[] = ['url' => $url, 'headers' => $headers, 'body' => json_decode($body, true), 'timeout' => $timeout];
        return ['status' => 200, 'body' => json_encode(['translations' => [['detected_source_language' => 'ES', 'text' => 'Hello world']]])];
    };
    $adapter = BrvtalDeepLHttpTransport::adapterFromEnvironment($http, real_env($on), $budget);
    $translated = $adapter->translate('Hola mundo', 'es', 'en');
    $rejects = [
        real_error(static fn() => $adapter->translate('Hola', 'es', 'fr')),
        real_error(static fn() => $adapter->translate(str_repeat('a', 5001), 'es', 'en')),
    ];
    $direct = new BrvtalDeepLHttpTransport($http, real_env($on), $budget);
    $beforeDirect = count($seen);
    $directRequest = static fn(string $value): array => [
        'text' => [$value], 'source_lang' => 'ES', 'target_lang' => 'EN',
    ];
    $directRejects = [
        real_error(static fn() => $direct($directRequest(str_repeat('a', 5001)))),
        real_error(static fn() => $direct($directRequest("\xC3\x28"))),
        real_error(static fn() => $direct($directRequest('<b>Hola</b>'))),
    ];
    $directCalls = count($seen) - $beforeDirect;
    real_report([
        'direct_rejects' => $directRejects,
        'direct_http_calls' => $directCalls,
        'translated' => $translated,
        'version' => $adapter->version(),
        'calls' => count($seen),
        'request' => $seen[0] ?? null,
        'rejects' => $rejects,
        'key_in_headers_only' => in_array('Authorization: DeepL-Auth-Key ' . DEEPL_REAL_FIXTURE_KEY, $seen[0]['headers'] ?? [], true),
        'key_in_body' => str_contains(json_encode($seen[0]['body'] ?? []), DEEPL_REAL_FIXTURE_KEY),
    ]);
}

if ($scenario === 'errors') {
    $variants = [
        'transport' => static function (): array { throw new RuntimeException('boom ' . DEEPL_REAL_FIXTURE_KEY); },
        'http_456' => static fn(): array => ['status' => 456, 'body' => '{"message":"Quota exceeded"}'],
        'http_403' => static fn(): array => ['status' => 403, 'body' => 'forbidden'],
        'malformed' => static fn(): array => ['status' => 200, 'body' => '{not json'],
        'shape' => static fn(): array => ['status' => 200, 'body' => '{"translations":[]}'],
    ];
    $reasons = [];
    $fallbacks = [];
    foreach ($variants as $name => $http) {
        $adapter = BrvtalDeepLHttpTransport::adapterFromEnvironment($http, real_env($on), $budget);
        $reasons[$name] = real_error(static fn() => $adapter->translate('Hola', 'es', 'en'));
        $fallbacks[$name] = brvtalPublicTranslationResolve(
            'Hola', 'en', $adapter,
            static fn(array $identity): ?string => null,
            static function (array $identity, string $text): void { throw new RuntimeException('cache should not be written'); }
        );
    }
    real_report(['reasons' => $reasons, 'fallbacks' => $fallbacks]);
}

if ($scenario === 'budget') {
    $calls = 0;
    $http = static function () use (&$calls): array {
        $calls++;
        return ['status' => 200, 'body' => json_encode(['translations' => [['text' => 'Hello']]])];
    };
    $now = gmmktime(12, 0, 0, 10, 15, 2026);
    $env = real_env($on + ['BRVTAL_DEEPL_MONTHLY_CHARS' => '10']);
    $transport = new BrvtalDeepLHttpTransport($http, $env, $budget, static fn(): int => $now);
    $request = ['text' => ['Hola'], 'source_lang' => 'ES', 'target_lang' => 'EN'];
    $transport($request);
    $transport($request);
    $exhausted = real_error(static fn() => $transport($request));
    $callsAtLimit = $calls;
    $nextMonth = new BrvtalDeepLHttpTransport($http, $env, $budget, static fn(): int => gmmktime(12, 0, 0, 11, 1, 2026));
    $nextMonth($request);
    $badLimit = new BrvtalDeepLHttpTransport($http, real_env($on + ['BRVTAL_DEEPL_MONTHLY_CHARS' => 'abc']), $budget, static fn(): int => $now);
    $badLimit($request);
    $saved = json_decode((string)file_get_contents($budget), true);
    file_put_contents($budget, '{"month":');
    $beforeMalformed = $calls;
    $malformedRecord = real_error(static fn() => $nextMonth($request));
    file_put_contents($budget, '');
    $emptyRecord = real_error(static fn() => $nextMonth($request));
    $malformedHttpCalls = $calls - $beforeMalformed;
    real_report([
        'malformed_record' => $malformedRecord,
        'empty_record' => $emptyRecord,
        'malformed_http_calls' => $malformedHttpCalls,
        'exhausted' => $exhausted,
        'calls_at_limit' => $callsAtLimit,
        'calls_after_new_month' => $calls,
        'saved' => $saved,
    ]);
}

fwrite(STDERR, "unknown scenario\n");
exit(2);
