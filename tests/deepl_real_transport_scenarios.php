<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/public_translation_deepl_transport.php';

function expect_true(bool $ok, string $name): void {
    if (!$ok) { throw new RuntimeException('TEST_' . $name); }
}
function thrown_reason(callable $fn): string {
    try { $fn(); } catch (Throwable $e) { return $e->getMessage(); }
    return 'NO_ERROR';
}
function report_result(array $data): never {
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}
$fakeKey = 'SYNTHETIC_ONLY_PRIVATE_FIXTURE';
$env = static fn(string $name): mixed => match ($name) {
    'BRVTAL_DEEPL_ENABLED' => '1', 'DEEPL_AUTH_KEY' => $fakeKey, default => null
};
$scenario = $argv[1] ?? '';
if ($scenario === 'off') {
    $calls = 0;
    $send = static function () use (&$calls): array { $calls++; return []; };
    $disabled = brvtalPublicDeepLRealAdapter(
        static fn(string $n): mixed => $n === 'DEEPL_AUTH_KEY' ? 'fake' : null, $send);
    $noKey = brvtalPublicDeepLRealAdapter(
        static fn(string $n): mixed => $n === 'BRVTAL_DEEPL_ENABLED' ? '1' : null, $send);
    $a = thrown_reason(static fn(): string => $disabled->translate('Hola', 'es', 'en'));
    $b = thrown_reason(static fn(): string => $noKey->translate('Hola', 'es', 'en'));
    $fall = brvtalPublicTranslationResolve('Hola', 'en', $disabled,
        static fn(array $x): ?string => null,
        static function (): void { throw new RuntimeException('CACHE_WRITE'); });
    expect_true($calls === 0 && $a === 'provider_not_configured' && $a === $b, 'DISABLED');
    report_result(['reasons'=>[$a,$b], 'calls'=>$calls,'fallback'=>$fall]);
}
if ($scenario === 'request') {
    $calls = 0;
    $budgetCalls = 0;
    $send = static function (string $url, array $headers, string $body, int $seconds)
        use (&$calls, $fakeKey): array {
        $calls++;
        $request = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        expect_true($url === 'https://api-free.deepl.com/v2/translate', 'URL');
        expect_true(in_array('Authorization: DeepL-Auth-Key ' . $fakeKey, $headers, true), 'KEY_HEADER');
        expect_true(in_array('Content-Type: application/json', $headers, true), 'TYPE');
        expect_true($seconds === 3 && $request['source_lang'] === 'ES'
            && $request['target_lang'] === 'EN-US' && count($request['text']) === 1
            && strlen($request['text'][0]) <= 5000, 'PAYLOAD');
        return ['status'=>200,'body'=>'{"translations":[{"text":"Hello"}]}'];
    };
    $charge = static function (int $count,string $month,int $limit) use (&$budgetCalls): bool {
        $budgetCalls++;
        expect_true($count > 0 && $limit === 300000
            && preg_match('/^\d{4}-\d{2}$/', $month) === 1, 'BUDGET');
        return true;
    };
    $adapter = brvtalPublicDeepLRealAdapter($env, $send, $charge);
    $hello = $adapter->translate('Hola', 'es', 'en');
    $invalid = thrown_reason(static fn(): string =>
        $adapter->translate(str_repeat('x', 5001), 'es', 'en'));
    expect_true($calls === 1 && $budgetCalls === 1, 'COUNT');
    report_result(['result'=>$hello,'calls'=>$calls,'reserved'=>$budgetCalls,
        'invalid'=>$invalid,'version'=>$adapter->version()]);
}
if ($scenario === 'errors') {
    $calls = 0;
    $written = 0;
    $reasons = [];
    $fallbacks = [];
    foreach (['timeout','http','invalid','budget'] as $mode) {
        $send = static function () use ($mode, &$calls, $fakeKey): array {
            $calls++;
            if ($mode === 'timeout') { throw new RuntimeException($fakeKey); }
            if ($mode === 'http') { return ['status'=>429,'body'=>'rate limited']; }
            return ['status'=>200,'body'=>'{invalid'];
        };
        $charge = static fn(int $chars,string $month,int $limit):bool => $mode !== 'budget';
        $adapter = brvtalPublicDeepLRealAdapter($env, $send, $charge);
        $reasons[$mode] = thrown_reason(static fn(): string =>
            $adapter->translate('Hola','es','en'));
        $fallbacks[$mode] = brvtalPublicTranslationResolve('Hola','en',$adapter,
            static fn(array $id): ?string => null,
            static function(array $id,string $translation) use (&$written): void { $written++; });
    }
    expect_true($calls === 6 && $written === 0, 'FAIL_CLOSED');
    report_result(['reasons'=>$reasons,'fallbacks'=>$fallbacks,
        'calls'=>$calls,'writes'=>$written]);
}
if ($scenario === 'contract') {
    $calls = 0;
    $adapter = brvtalPublicDeepLRealAdapter($env,
        static function() use (&$calls):array { $calls++; return []; },
        static fn(int $n,string $m,int $l):bool => true);
    $spanish = brvtalPublicTranslationResolve('Hola','es',$adapter,
        static fn(array $id):?string => null,
        static function():void {throw new RuntimeException('WRONG_CACHE');});
    expect_true($calls === 0 && $spanish['text'] === 'Hola', 'SPANISH');
    report_result(['calls'=>$calls,'spanish'=>$spanish,'network'=>false]);
}
fwrite(STDERR, "Unknown offline test case\n");
exit(2);
