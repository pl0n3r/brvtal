<?php
declare(strict_types=1);

require_once __DIR__ . '/public_translation_deepl.php';

/**
 * Real DeepL API Free transport, OFF by default.
 *
 * Active only when BRVTAL_DEEPL_ENABLED=1 and DEEPL_AUTH_KEY exist in the server
 * environment. Any problem collapses to RuntimeException, which the adapter turns
 * into provider_unavailable so the site keeps showing canonical Spanish. The key
 * is read here at call time, is never stored in a property, and never appears in
 * errors, logs or return values.
 */
final class BrvtalDeepLHttpTransport
{
    public const string ENDPOINT = 'https://api-free.deepl.com/v2/translate';
    public const int TIMEOUT_SECONDS = 5;
    public const int DEFAULT_MONTHLY_CHARS = 300000;
    public const string VERSION = 'deepl-api-free-es-en-v1';

    private readonly Closure $http;
    private readonly Closure $env;
    private readonly string $budgetFile;
    private readonly Closure $clock;

    /**
     * @param callable(string $url, array<int,string> $headers, string $body, int $timeout): array{status:int, body:string}|null $http
     * @param callable(string): ?string|null $env
     */
    public function __construct(?callable $http = null, ?callable $env = null, ?string $budgetFile = null, ?callable $clock = null)
    {
        $this->http = Closure::fromCallable($http ?? [self::class, 'curlRequest']);
        $this->env = Closure::fromCallable($env ?? static function (string $name): ?string {
            $value = getenv($name);
            return $value === false ? null : $value;
        });
        $this->budgetFile = $budgetFile ?? dirname(__DIR__) . '/storage/deepl-usage.json';
        $this->clock = Closure::fromCallable($clock ?? static fn(): int => time());
    }

    public static function isEnabled(callable $env): bool
    {
        $enabled = $env('BRVTAL_DEEPL_ENABLED');
        $key = $env('DEEPL_AUTH_KEY');
        return $enabled === '1' && is_string($key) && trim($key) !== '';
    }

    /** Builds the adapter only when the switch and the key are present; null keeps Spanish. */
    public static function adapterFromEnvironment(?callable $http = null, ?callable $env = null, ?string $budgetFile = null): ?BrvtalDeepLTranslationAdapter
    {
        $transport = new self($http, $env, $budgetFile);
        if (!self::isEnabled($transport->env)) {
            return null;
        }
        return new BrvtalDeepLTranslationAdapter($transport, $transport->env, self::VERSION);
    }

    /** @param array{text?:mixed,source_lang?:mixed,target_lang?:mixed} $request */
    public function __invoke(array $request): array
    {
        if (!self::isEnabled($this->env)) {
            throw new RuntimeException('deepl_disabled');
        }
        $text = $request['text'] ?? null;
        if (
            !is_array($text) || count($text) !== 1 || !array_is_list($text) || !is_string($text[0])
            || ($request['source_lang'] ?? null) !== 'ES' || ($request['target_lang'] ?? null) !== 'EN'
        ) {
            throw new RuntimeException('deepl_bad_request');
        }
        $this->reserveBudget(mb_strlen($text[0], 'UTF-8'));

        $key = (string)($this->env)('DEEPL_AUTH_KEY');
        $body = json_encode(
            ['text' => [$text[0]], 'source_lang' => 'ES', 'target_lang' => 'EN-US'],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        );
        $headers = [
            'Authorization: DeepL-Auth-Key ' . trim($key),
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        try {
            $response = ($this->http)(self::ENDPOINT, $headers, $body, self::TIMEOUT_SECONDS);
        } catch (Throwable) {
            throw new RuntimeException('deepl_transport_error');
        }
        if (!is_array($response) || ($response['status'] ?? 0) !== 200 || !is_string($response['body'] ?? null)) {
            throw new RuntimeException('deepl_http_status');
        }
        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('deepl_bad_response');
        }
        return $decoded;
    }

    private function monthlyLimit(): int
    {
        $raw = ($this->env)('BRVTAL_DEEPL_MONTHLY_CHARS');
        if (is_string($raw) && preg_match('/^[1-9][0-9]{0,6}$/', $raw) === 1) {
            return (int)$raw;
        }
        return self::DEFAULT_MONTHLY_CHARS;
    }

    private function reserveBudget(int $chars): void
    {
        $month = gmdate('Y-m', ($this->clock)());
        $handle = @fopen($this->budgetFile, 'c+');
        if ($handle === false) {
            throw new RuntimeException('deepl_budget_unavailable');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('deepl_budget_unavailable');
            }
            $saved = json_decode((string)stream_get_contents($handle), true);
            $used = (is_array($saved) && ($saved['month'] ?? null) === $month && is_int($saved['chars'] ?? null))
                ? $saved['chars'] : 0;
            if ($used + $chars > $this->monthlyLimit()) {
                throw new RuntimeException('deepl_budget_exhausted');
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode(['month' => $month, 'chars' => $used + $chars], JSON_THROW_ON_ERROR));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array{status:int, body:string} */
    public static function curlRequest(string $url, array $headers, string $body, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('curl_unavailable');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $result = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($result)) {
            throw new RuntimeException('curl_failed');
        }
        return ['status' => $status, 'body' => $result];
    }
}
