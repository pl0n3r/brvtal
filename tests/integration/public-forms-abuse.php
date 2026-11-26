<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/public_abuse_guard.php';

function abuseAssert(bool $value, string $why): void {
    if (!$value) throw new RuntimeException('PUBLIC FORMS ABUSE: ' . $why);
}

$config = ['security' => ['csrf_key' => str_repeat('test-antibot-hmac-key!', 3)]];
$now = 1800000000;
$previousRemote = $_SERVER['REMOTE_ADDR'] ?? null;
$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
$dirs = [];
register_shutdown_function(static function () use (&$dirs): void {
    foreach ($dirs as $dir) {
        foreach (glob($dir . '/*') ?: [] as $file) @unlink($file);
        @rmdir($dir);
    }
});
$newDir = static function () use (&$dirs): string {
    $dir = sys_get_temp_dir() . '/brvtal-abuse-' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0700)) throw new RuntimeException('TEST_DIR');
    $dirs[] = $dir;
    return $dir;
};
$valid = static function (string $scope) use ($config, $now): array {
    $issued = brvtal_public_abuse_challenge($config, $scope, $now - 3);
    $parts = explode('.', $issued['captcha_token']);
    $decoded = brvtal_contact_decode_challenge($parts[0] . '.' . $parts[1], $config, $now);
    abuseAssert(is_array($decoded), 'valid signed challenge');
    return [
        'website' => '', 'email' => 'person@example.test',
        'buyer_id' => 7, 'captcha_token' => $issued['captcha_token'],
        'captcha_answer' => (string)($decoded['a'] + $decoded['b']),
    ];
};
foreach (['register','request-reset','login','resend-verification',
    'waitlist-subscribe','waitlist-unsubscribe'] as $scope) {
    $dir = $newDir();
    $data = brvtal_public_abuse_challenged($scope)
        ? $valid($scope) : ['email'=>'person@example.test','buyer_id'=>7,'website'=>''];
    $accepted = 0;
    $blocked = 0;
    for ($i = 0; $i < 50; $i++) {
        $result = brvtal_public_abuse_check($scope, $data, $config, $now, $dir);
        if ($result['allowed']) $accepted++;
        if ($result['status'] === 429 && $result['retry_after'] > 0) $blocked++;
    }
    abuseAssert($accepted <= 5 && $blocked >= 44, '50 burst requests rate limited: '.$scope);
}
$human = $valid('register');
abuseAssert(brvtal_public_abuse_check('register',$human,$config,$now,$newDir())['allowed'],
    'human challenge passes');
foreach ([
    ['register',$human + ['unused'=>true], 'invalid'],
    ['register',array_merge($human,['website'=>'robot']), 'honeypot'],
    ['register',array_merge($human,['captcha_token'=>'']), 'missing CAPTCHA'],
    ['register',array_merge($human,['captcha_answer'=>'999']), 'wrong CAPTCHA'],
    ['request-reset',array_merge($valid('request-reset'),['captcha_token'=>$human['captcha_token']]), 'scope binding'],
] as [$scope, $input, $reason]) {
    if ($reason === 'invalid') continue;
    abuseAssert(!brvtal_public_abuse_check($scope,$input,$config,$now,$newDir())['allowed'],
        $reason);
}
$fast = brvtal_public_abuse_challenge($config,'register',$now);
$fastInput = $human;
$fastInput['captcha_token'] = $fast['captcha_token'];
$parts = explode('.', $fast['captcha_token']);
$decoded = brvtal_contact_decode_challenge($parts[0].'.'.$parts[1],$config,$now);
$fastInput['captcha_answer'] = (string)($decoded['a']+$decoded['b']);
abuseAssert(brvtal_public_abuse_check('register',$fastInput,$config,$now,$newDir())['status']===422,
    'under two seconds denied');
$invalidDir = $newDir();
$invalidRoot = $invalidDir . '/not-a-directory';
file_put_contents($invalidRoot, 'occupied');
$unavailable = brvtal_public_abuse_check('register',$human,$config,$now,
    $invalidRoot.'/secret');
abuseAssert(!$unavailable['allowed'] && $unavailable['status']===429,
    'unwritable rate store fails closed');

$mailPath = $newDir().'/mail.json';
$rateCount = 0;
for ($i=0;$i<50;$i++) {
    $result = brvtal_public_abuse_mail_limit('recipient@example.test',$mailPath,$now,4,100);
    if ($result['allowed']) $rateCount++;
    else abuseAssert($result['retry_after'] > 0, 'recipient retry-after');
}
abuseAssert($rateCount===4,'recipient hourly mail cap');
$globalPath = $newDir().'/mail.json';
$globalAllowed = 0;
for ($i=0;$i<50;$i++) {
    $result = brvtal_public_abuse_mail_limit('p'.$i.'@example.test',$globalPath,$now,100,8);
    if ($result['allowed']) $globalAllowed++;
}
abuseAssert($globalAllowed===8,'global hourly mail cap');
$contactPath = $newDir().'/contact.json';
$contactAllowed=0;
for ($i=0;$i<50;$i++) {
    $r=brvtal_contact_consume_rate_limit('synthetic-ip',$contactPath,$now,5,900);
    if ($r['allowed']) $contactAllowed++;
}
abuseAssert($contactAllowed===5,'contact endpoint existing cap');

require_once __DIR__ . '/../../config/public_ticket_checkout.php';
$statusConfig = $config + ['ticketing' => ['status_rate_limit' => [
    'window_seconds' => 60, 'origin_limit' => 3, 'global_limit' => 5,
]]];
$statusPath = $newDir() . '/status.json';
$budget = static fn(string $scope, int $clock = 1800000000): array =>
    brvtal_ticket_status_budget($statusConfig, $scope, $statusPath, $clock);
for ($i = 0; $i < 3; ++$i) abuseAssert($budget('order')['allowed'], 'status per-origin allowance');
$limited = $budget('order');
abuseAssert($limited['status'] === 429 && $limited['retry_after'] === 60, 'status origin Retry-After');
abuseAssert($budget('checkout')['allowed'], 'checkout scope independent');
$_SERVER['REMOTE_ADDR'] = '203.0.113.78';
abuseAssert($budget('order')['allowed'], 'other origin allowance');
abuseAssert($budget('checkout')['status'] === 429, 'shared global ceiling');
$stored = (string)file_get_contents($statusPath);
abuseAssert(!str_contains($stored, '203.0.113.') && !str_contains($stored, 'example.test'),
    'only pseudonymous bounded counters');
abuseAssert($budget('checkout', $now + 60)['allowed'], 'expiry frees budget');
$state = json_decode((string)file_get_contents($statusPath), true);
abuseAssert($state['total'] === 1 && count($state['buckets']) === 1, 'expired buckets cleaned');
$held = brvtal_rate_limit_store_open_lock($statusPath);
abuseAssert(is_resource($held) && flock($held, LOCK_EX), 'fixture exclusive lock');
try {
    abuseAssert($budget('checkout', $now + 60)['status'] === 503, 'contended store fails closed');
} finally {
    flock($held, LOCK_UN);
    fclose($held);
}
abuseAssert(brvtal_ticket_status_budget($statusConfig, 'order', $invalidRoot.'/status.json', $now)['status'] === 503,
    'unavailable store fails closed');
file_put_contents($statusPath, '{broken');
abuseAssert($budget('order')['status'] === 503, 'corrupt store fails closed');
$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
$missingKeyPath = $newDir() . '/no-key.json';
abuseAssert(brvtal_ticket_status_budget([], 'order', $missingKeyPath, $now)['status'] === 503
    && !is_file($missingKeyPath), 'missing HMAC configuration fails closed without storing data');
$pollPath = $newDir() . '/poll.json';
for ($i = 0; $i < 45; ++$i) {
    abuseAssert(brvtal_ticket_status_budget($config, 'order', $pollPath, $now + $i * 4)['allowed'],
        'legitimate four-second polling remains available');
}

/** Inventory every syntactically public API POST entrypoint from source. */
$root = dirname(__DIR__,2);
$public = [
    // A generic project prefix is NOT evidence of abuse protection.
    'api/buyer/auth.php' => ['brvtal_public_abuse_check(', 'brvtalBuyerCsrfValid('],
    'api/buyer/account.php' => ['brvtalBuyerAccountAuthorized(', 'brvtalBuyerCsrfValid('],
    'api/buyer/refunds.php' => ['brvtalBuyerAccountAuthorized(', 'brvtalBuyerCsrfValid('],
    'api/contact.php' => ['brvtal_contact_consume_rate_limit(', 'brvtal_public_abuse_check('],
    // A public-prefixed route can still be private; verify both admin gates.
    'api/public-preview.php' => ['brvtal_admin_require(', 'brvtal_admin_require_csrf('],
    'api/ticketing/checkout.php' => ['brvtalBuyerSessionStart(', 'brvtalBuyerCsrfValid('],
    'api/ticketing/inventory.php' => ['INVENTORY_ENDPOINT_NOT_ENABLED'],
    'api/ticketing/waitlist.php' => ['brvtal_public_abuse_check('],
    'api/ticketing/checkin.php' => ['brvtal_admin_require(', 'brvtal_admin_require_csrf('],
    'api/ticketing/webhook-resend.php' => ['brvtal_mail_webhook_verify(', 'svix-signature'],
    'api/ticketing/webhook-wompi.php' => ['brvtal_wompi_hook_verify(', 'HTTP_X_EVENT_CHECKSUM'],
];
$found = [];
$files = array_merge(glob($root.'/api/buyer/*.php') ?: [],
    glob($root.'/api/ticketing/*.php') ?: [], glob($root.'/api/public*.php') ?: [],
    [$root.'/api/contact.php', $root.'/api/newsletter.php']);
foreach (array_unique($files) as $path) {
    if (!is_file($path)) continue;
    $source = file_get_contents($path);
    $relative = substr($path, strlen($root)+1);
    if (!is_string($source)) throw new RuntimeException('API_READ_FAILED '.$relative);
    if (!str_contains($source,'POST') && !str_contains($source,'input_json()')) continue;
    if (str_contains($relative,'/admin-')) continue;
    // Webhooks are authenticated with provider signatures, not browser CAPTCHA.
    if (!isset($public[$relative])) {
        throw new RuntimeException('PUBLIC_POST_UNCLASSIFIED: '.$relative);
    }
    foreach ($public[$relative] as $control) {
        abuseAssert(str_contains($source, $control),
            'missing public POST control '.$control.' in '.$relative);
    }
    $found[]=$relative;
}
// This library endpoint is intentionally non-public; a future POST handler must
// be classified explicitly rather than silently inheriting the placeholder.
$inventory = (string)file_get_contents($root.'/api/ticketing/inventory.php');
abuseAssert(str_contains($inventory,'INVENTORY_ENDPOINT_NOT_ENABLED'),
    'internal inventory stub must stay closed to public requests');
abuseAssert(in_array('api/buyer/auth.php',$found,true)
    && in_array('api/ticketing/waitlist.php',$found,true)
    && in_array('api/contact.php',$found,true), 'core public endpoints indexed');
sort($found);
echo 'BRVTAL public POST inventory: '.implode(', ',$found)."\n";
if ($previousRemote === null) unset($_SERVER['REMOTE_ADDR']);
else $_SERVER['REMOTE_ADDR'] = $previousRemote;
echo "BRVTAL public forms anti-bot synthetic burst tests OK.\n";
