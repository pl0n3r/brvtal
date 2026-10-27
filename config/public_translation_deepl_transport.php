<?php
declare(strict_types=1);

require_once __DIR__ . '/public_translation_deepl.php';

/**
 * Source-only factory. Production does NOT instantiate this adapter in #1034.
 * The two environment switches must be set explicitly in a later authorized rollout.
 * Inject HTTP and budget callables for all automated tests: no external network.
 */
function brvtalPublicDeepLRealAdapter(
    ?callable $env = null,
    ?callable $sendHttp = null,
    ?callable $charge = null
): BrvtalDeepLTranslationAdapter {
    $reader = $env ?? static fn(string $key): mixed => getenv($key);
    try {
        $enabled = $reader('BRVTAL_DEEPL_ENABLED') === '1';
        $rawKey = $reader('DEEPL_AUTH_KEY');
        $key = is_string($rawKey) && trim($rawKey) !== '' ? trim($rawKey) : null;
        $rawLimit = $reader('BRVTAL_DEEPL_MONTHLY_BUDGET');
        $limit = ($rawLimit === false || $rawLimit === null || $rawLimit === '') ? 300000 :
            filter_var($rawLimit, FILTER_VALIDATE_INT);
        $limit = is_int($limit) && $limit >= 1 && $limit <= 500000 ? $limit : 0;
    } catch (Throwable) {
        $enabled = false;
        $key = null;
        $limit = 0;
    }
    if (!$enabled || $key === null || $limit === 0) {
        return new BrvtalDeepLTranslationAdapter(
            static fn(array $payload): array => [],
            null,
            'deepl-disabled-es-en-us-v1'
        );
    }
    $send = $sendHttp ?? 'brvtalDeepLHttpPost';
    $reserve = $charge ?? 'brvtalDeepLReserveCharacters';
    $transport = static function (array $payload) use ($key, $limit, $send, $reserve): array {
        if (
            ($payload['source_lang'] ?? null) !== 'ES' || ($payload['target_lang'] ?? null) !== 'EN'
            || !isset($payload['text']) || !is_array($payload['text']) || count($payload['text']) !== 1
            || !is_string($payload['text'][0]) || strlen($payload['text'][0]) > 5000
        ) {
            throw new RuntimeException('provider_unavailable');
        }
        $count = preg_match_all('/./us', $payload['text'][0]);
        if (!is_int($count) || $count < 1) {
            throw new RuntimeException('provider_unavailable');
        }
        try {
            if ($reserve($count, gmdate('Y-m'), $limit) !== true) {
                throw new RuntimeException('provider_unavailable');
            }
            $body = json_encode([
                'text' => [$payload['text'][0]],
                'source_lang' => 'ES',
                'target_lang' => 'EN-US',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $reply = $send(
                'https://api-free.deepl.com/v2/translate',
                ['Authorization: DeepL-Auth-Key ' . $key, 'Content-Type: application/json'],
                $body, 3
            );
        } catch (Throwable) {
            throw new RuntimeException('provider_unavailable');
        }
        if (!is_array($reply) || ($reply['status'] ?? null) !== 200) {
            throw new RuntimeException('provider_unavailable');
        }
        $json = $reply['body'] ?? null;
        if (!is_string($json) || strlen($json) > 65536) {
            return []; // Invalid response is sanitized by the adapter.
        }
        try {
            $decoded = json_decode($json, true, 12, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    };
    return new BrvtalDeepLTranslationAdapter(
        $transport,
        static fn(string $name): ?string => $name === 'DEEPL_AUTH_KEY' ? 'configured' : null,
        'deepl-free-es-en-us-v1'
    );
}

/** The only possible real HTTP path. Never redirects, retries or emits body/secret logs. */
function brvtalDeepLHttpPost(string $url, array $headers, string $json, int $timeout): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('provider_unavailable');
    }
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('provider_unavailable');
    }
    try {
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if (!is_string($response)) {
            throw new RuntimeException('provider_unavailable');
        }
        return ['status' => $status, 'body' => $response];
    } finally {
        curl_close($curl);
    }
}

/**
 * Atomic local quota accounting across PHP requests. Store only month+counter,
 * no text, keys, identities or provider response. Storage failures fail closed.
 * Multi-host rollout must stay OFF until a shared authoritative counter exists.
 */
function brvtalDeepLReserveCharacters(int $characters, string $month, int $budget): bool
{
    if ($characters < 1 || $characters > $budget || $budget > 500000
        || preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
        return false;
    }
    $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) .
        DIRECTORY_SEPARATOR . 'brvtal-deepl-' . substr(hash('sha256', __DIR__), 0, 20) . '.json';
    if (is_link($path)) {
        return false;
    }
    $file = @fopen($path, 'c+');
    if ($file === false) {
        return false;
    }
    try {
        if (!flock($file, LOCK_EX) || is_link($path)) {
            return false;
        }
        $raw = stream_get_contents($file);
        if (!is_string($raw) || strlen($raw) > 256) {
            return false;
        }
        $stored = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($stored) || ($raw !== '' && !isset($stored['month'], $stored['used']))) {
            return false;
        }
        $spent = ($stored['month'] ?? '') === $month ? ($stored['used'] ?? 0) : 0;
        if (!is_int($spent) || $spent < 0 || $spent > $budget || $characters > $budget - $spent) {
            return false;
        }
        $next = json_encode(['month' => $month, 'used' => $spent + $characters], JSON_THROW_ON_ERROR);
        if (!rewind($file) || !ftruncate($file, 0) || fwrite($file, $next) !== strlen($next)) {
            return false;
        }
        return fflush($file);
    } catch (Throwable) {
        return false;
    } finally {
        flock($file, LOCK_UN);
        fclose($file);
    }
}
