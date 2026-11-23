<?php
declare(strict_types=1);

/** #1940: a checkout releases the buyer session before touching the database and caps lock waits. */
function checkoutSessionAssert(bool $ok, string $why): void
{
    if (!$ok) {
        fwrite(STDERR, "CHECKOUT SESSION CONTRACT FAILED: {$why}\n");
        exit(1);
    }
}
$source = (string)file_get_contents(__DIR__ . '/../config/public_ticket_checkout.php');
$http = substr($source, (int)strpos($source, 'function brvtal_public_ticket_checkout_http'));
$close = strpos($http, 'session_write_close()');
$prepare = strpos($http, 'brvtal_public_ticket_checkout_prepare(');
$state = strpos($http, 'brvtal_public_ticket_checkout_state(');
checkoutSessionAssert($close !== false && $prepare !== false && $state !== false, 'handler pieces present');
checkoutSessionAssert($close < $prepare && $close < $state, 'session lock released before any checkout database work');
checkoutSessionAssert(strpos($http, 'brvtalBuyerCsrfValid(') < $close, 'CSRF read before the session is closed');
foreach (['function brvtal_public_ticket_checkout_prepare_cart', 'function brvtal_public_ticket_checkout_prepare('] as $fn) {
    $body = substr($source, (int)strpos($source, $fn));
    $locks = strpos($body, 'brvtal_public_ticket_checkout_fast_locks($db)');
    $begin = strpos($body, '$db->beginTransaction()');
    checkoutSessionAssert($locks !== false && $begin !== false && $locks < $begin, "{$fn}: short lock wait set before the transaction");
}
checkoutSessionAssert(str_contains($source, "SET SESSION innodb_lock_wait_timeout = 5"), 'lock wait capped at 5 s');
checkoutSessionAssert(substr_count($http, 'brvtal_public_ticket_checkout_log($error') >= 3, 'every failure path is logged');
echo "BRVTAL #1940 checkout session/lock contract passed\n";
