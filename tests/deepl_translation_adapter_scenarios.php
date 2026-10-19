<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_translation_deepl.php';
require_once __DIR__ . '/../config/public_translation_overlay.php';

function deepl_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('DEEPL FAKE TEST: ' . $message);
    }
}

function deepl_error(callable $operation): string
{
    try {
        $operation();
    } catch (Throwable $error) {
        return $error->getMessage();
    }
    throw new RuntimeException('DEEPL FAKE TEST: expected rejection');
}

function deepl_report(array $result): never
{
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    echo "\n";
    exit(0);
}

$scenario = $argv[1] ?? '';
$fixtureKey = 'fixture-key-must-never-be-exposed';
$read = static fn(string $name): ?string => $name === 'DEEPL_AUTH_KEY' ? $fixtureKey : null;

if ($scenario === 'missing') {
    $calls = 0;
    $transport = static function (array $request) use (&$calls): array {
        $calls++;
        return ['translations' => [['text' => 'Hello']]];
    };
    $adapter = new BrvtalDeepLTranslationAdapter($transport);
    $error = deepl_error(static fn(): string => $adapter->translate('Hola', 'es', 'en'));
    $result = brvtalPublicTranslationResolve(
        'Hola', 'en', $adapter,
        static fn(array $identity): ?string => null,
        static function (array $identity, string $text): void {
            throw new RuntimeException('cache should not be written');
        }
    );
    $brokenReader = new BrvtalDeepLTranslationAdapter(
        $transport,
        static function (string $name): never {
            throw new RuntimeException('fixture secret detail');
        }
    );
    deepl_expect(
        deepl_error(static fn(): string => $brokenReader->translate('Hola', 'es', 'en')) === 'provider_not_configured',
        'configuration error must fail closed'
    );
    deepl_expect($calls === 0, 'missing configuration must not call transport');
    deepl_report(['reason' => $error, 'result' => $result, 'transport_calls' => $calls]);
}

if ($scenario === 'valid') {
    $calls = 0;
    $transport = static function (array $payload) use (&$calls): array {
        $calls++;
        deepl_expect(array_keys($payload) === ['text', 'source_lang', 'target_lang'], 'payload shape');
        deepl_expect($payload['source_lang'] === 'ES' && $payload['target_lang'] === 'EN', 'locale pair');
        deepl_expect(count($payload['text']) === 1, 'one text only');
        return ['translations' => [['text' => 'EN ' . $payload['text'][0]]]];
    };
    $adapter = new BrvtalDeepLTranslationAdapter($transport, $read);
    $translated = $adapter->translate('Hola mundo', 'es', 'en');
    $rejects = [
        deepl_error(static fn(): string => $adapter->translate('<b>HTML</b>', 'es', 'en')),
        deepl_error(static fn(): string => $adapter->translate('Hola', 'en', 'es')),
        deepl_error(static fn(): string => $adapter->translate(str_repeat('x', 5001), 'es', 'en')),
        deepl_error(static fn(): string => $adapter->translate("privado\x00", 'es', 'en')),
    ];
    $tooLarge = new BrvtalDeepLTranslationAdapter(
        static fn(array $payload): array => ['translations' => [['text' => str_repeat('Y', 10001)]]],
        $read
    );
    $rejects[] = deepl_error(static fn(): string => $tooLarge->translate('Hola', 'es', 'en'));
    deepl_expect($calls === 1, 'invalid input must not reach transport');
    deepl_report(['translated' => $translated, 'version' => $adapter->version(), 'rejects' => $rejects, 'transport_calls' => $calls]);
}

if ($scenario === 'errors') {
    $reasons = [];
    $fallbacks = [];
    foreach (['timeout', 'http', 'malformed'] as $case) {
        $calls = 0;
        $transport = static function (array $request) use ($case, &$calls, $fixtureKey): array {
            $calls++;
            if ($case === 'malformed') {
                return ['translations' => [['text' => '<script>not safe</script>']]];
            }
            throw new RuntimeException($case . ' ' . $fixtureKey);
        };
        $adapter = new BrvtalDeepLTranslationAdapter($transport, $read);
        $reasons[$case] = deepl_error(static fn(): string => $adapter->translate('Hola', 'es', 'en'));
        $fallbacks[$case] = brvtalPublicTranslationResolve(
            'Hola', 'en', $adapter,
            static fn(array $identity): ?string => null,
            static function (array $identity, string $text): void {
                throw new RuntimeException('cache must remain empty');
            }
        );
        deepl_expect($calls === 2, 'fallback uses fake twice, without network');
    }
    deepl_report(['reasons' => $reasons, 'fallbacks' => $fallbacks]);
}

if ($scenario === 'cache') {
    $calls = 0;
    $adapter = new BrvtalDeepLTranslationAdapter(
        static function (array $payload) use (&$calls): array {
            $calls++;
            return ['translations' => [['text' => 'EN ' . $payload['text'][0]]]];
        },
        $read
    );
    $store = [];
    $cacheRead = static function (array $id) use (&$store): ?string {
        return $store[$id['cache_key']] ?? null;
    };
    $cacheWrite = static function (array $id, string $text) use (&$store): void {
        $store[$id['cache_key']] = $text;
    };
    $first = brvtalPublicTranslationResolve('Hola', 'en', $adapter, $cacheRead, $cacheWrite);
    $second = brvtalPublicTranslationResolve('Hola', 'en', $adapter, $cacheRead, $cacheWrite);
    $spanish = brvtalPublicTranslationResolve('Hola', 'es', $adapter, $cacheRead, $cacheWrite);
    $changed = brvtalPublicTranslationResolve('Hola!', 'en', $adapter, $cacheRead, $cacheWrite);
    $beforeVisibility = $calls;
    $source = [
        'id' => 41, 'route_type' => 'pages', 'slug' => 'pagina-demo',
        'status' => 'published', 'title' => 'Hola pagina',
        'description' => 'Descripcion', 'seo_title' => 'Titulo SEO',
        'seo_description' => 'Descripcion SEO',
    ];
    $private = brvtalPublicEditorialOverlayResolve(
        $source, 'en', $adapter, $cacheRead, $cacheWrite, false, true
    );
    deepl_expect($private === null && $beforeVisibility === $calls, 'private material cannot be translated');
    $trusted = brvtalPublicEditorialOverlayResolve(
        $source, 'en', $adapter, $cacheRead, $cacheWrite, true, true
    );
    deepl_expect(is_array($trusted), 'public trusted projection works through existing resolver');
    deepl_report([
        'first' => $first, 'second' => $second, 'spanish' => $spanish,
        'changed' => $changed, 'private' => $private,
        'trusted' => is_array($trusted), 'calls_before_visibility' => $beforeVisibility,
        'transport_calls' => $calls,
        'cache_keys' => count($store),
    ]);
}
fwrite(STDERR, "Unknown offline scenario\n");
exit(2);
