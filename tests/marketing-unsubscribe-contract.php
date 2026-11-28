<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/marketing-unsubscribe.php';

function unsubscribeExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('MARKETING_UNSUBSCRIBE_CONTRACT: ' . $message);
    }
}

$valid = str_repeat('a', 64);
$unknown = str_repeat('b', 64);
$grants = [hash('sha256', $valid) => true];
$calls = 0;
// In-memory synthetic capability ledger; the real service's SQL revocation,
// replay and transaction independence are covered by buyer-auth-flows.php.
$revoke = static function (string $token) use (&$grants, &$calls): void {
    ++$calls;
    $hash = hash('sha256', $token);
    if (isset($grants[$hash])) {
        $grants[$hash] = false;
    }
};

$get = brvtalMarketingUnsubscribeResponse('GET', ['token' => $valid], [], $revoke);
unsubscribeExpect($get['status'] === 200 && $calls === 0 && $grants[hash('sha256', $valid)], 'GET never revokes');
unsubscribeExpect(str_contains($get['body'], '<form method="post"') && str_contains($get['body'], $valid), 'GET offers explicit confirmation');
$getUnknown = brvtalMarketingUnsubscribeResponse('GET', ['token' => $unknown], [], $revoke);
unsubscribeExpect($getUnknown['body'] === str_replace($valid, $unknown, $get['body']) && $calls === 0, 'GET does not check existence');
$invalidGet = brvtalMarketingUnsubscribeResponse('GET', ['token' => '<script>'], [], $revoke);
unsubscribeExpect(!str_contains($invalidGet['body'], '<script>') && $calls === 0, 'unsafe input never reflected');

$post = brvtalMarketingUnsubscribeResponse('POST', [], ['token' => $valid], $revoke);
unsubscribeExpect($post['status'] === 200 && $calls === 1 && !$grants[hash('sha256', $valid)], 'valid POST revokes immediately');
$replay = brvtalMarketingUnsubscribeResponse('POST', [], ['token' => $valid], $revoke);
$missing = brvtalMarketingUnsubscribeResponse('POST', [], ['token' => $unknown], $revoke);
$malformed = brvtalMarketingUnsubscribeResponse('POST', [], ['token' => ['bad']], $revoke);
$ambiguous = brvtalMarketingUnsubscribeResponse('POST', ['token' => $valid], ['token' => $unknown], $revoke);
$queryOnly = brvtalMarketingUnsubscribeResponse('POST', ['token' => $valid], [], $revoke);
foreach ([$replay, $missing, $malformed, $ambiguous, $queryOnly] as $response) {
    unsubscribeExpect($response === $post, 'neutral identical response for all outcomes');
}
unsubscribeExpect($calls === 3, 'malformed, ambiguous and unconfirmed query-only POST do not reach storage');
$grants[hash('sha256', $valid)] = true;
$oneClick = brvtalMarketingUnsubscribeResponse('POST', ['token' => $valid], ['List-Unsubscribe' => 'One-Click'], $revoke);
unsubscribeExpect($oneClick === $post && $calls === 4 && !$grants[hash('sha256', $valid)], 'explicit one-click POST revokes');
$method = brvtalMarketingUnsubscribeResponse('DELETE', ['token' => $valid], [], $revoke);
unsubscribeExpect($method['status'] === 405 && $method['headers']['Allow'] === 'GET, POST' && $calls === 4, 'unsupported methods never mutate');
$failure = brvtalMarketingUnsubscribeResponse('POST', [], ['token' => $valid], static function (): void {
    throw new RuntimeException('secret@example.test');
});
unsubscribeExpect($failure['status'] === 503 && !str_contains($failure['body'], 'secret@example.test'), 'storage failure is sanitized');
foreach ([$get, $invalidGet, $post, $missing, $method, $failure] as $response) {
    unsubscribeExpect(str_contains($response['headers']['Cache-Control'], 'no-store')
        && str_contains($response['headers']['X-Robots-Tag'], 'noindex')
        && $response['headers']['Referrer-Policy'] === 'no-referrer', 'all responses forbid caching, indexing and referrer leakage');
}
unsubscribeExpect(!str_contains($post['body'], $valid) && !str_contains($missing['body'], $unknown), 'POST never echoes capability');
$original = getenv('BRVTAL_INTEGRATION_TESTS');
try {
    putenv('BRVTAL_INTEGRATION_TESTS=0');
    brvtalMarketingUnsubscribeRevoke($valid);
    unsubscribeExpect(!function_exists('db'), 'disabled route does not load DB bootstrap');
} finally {
    putenv($original === false ? 'BRVTAL_INTEGRATION_TESTS' : 'BRVTAL_INTEGRATION_TESTS=' . $original);
}
echo "Marketing unsubscribe contract passed.\n";
