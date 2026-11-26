<?php
declare(strict_types=1);

/** #1943: checkout without an account, against disposable MariaDB. */
require_once __DIR__ . '/../../config/public_ticket_checkout.php';
require_once __DIR__ . '/../../config/buyer_account.php';

function guestAssert(bool $ok, string $what): void
{
    if (!$ok) throw new RuntimeException('GUEST_CHECKOUT: ' . $what);
}
function guestRejects(callable $callback, string $code, string $what): void
{
    try {
        $callback();
    } catch (InvalidArgumentException | DomainException $error) {
        guestAssert($error->getMessage() === $code, $what . ' (got ' . $error->getMessage() . ')');
        return;
    }
    guestAssert(false, $what . ' was accepted');
}

// Input rules need no database.
$ok = brvtal_public_ticket_checkout_guest_input(['email'=>' Ana@Example.TEST ', 'name'=>"  Ana   Gómez ", 'consent'=>true, 'website'=>'']);
guestAssert($ok === ['email'=>'ana@example.test', 'name'=>'Ana Gómez'], 'email lowercased and name normalised');
foreach ([
    [['email'=>'no-es-correo','name'=>'Ana','consent'=>true], 'GUEST_EMAIL_INVALID'],
    [['email'=>'a@b.test','name'=>'A','consent'=>true], 'GUEST_NAME_INVALID'],
    [['email'=>'a@b.test','name'=>"Ana\u{0007}",'consent'=>true], 'GUEST_NAME_INVALID'],
    [['email'=>'a@b.test','name'=>'Ana','consent'=>'yes'], 'GUEST_CONSENT_REQUIRED'],
    [['email'=>'a@b.test','name'=>'Ana'], 'GUEST_CONSENT_REQUIRED'],
    [['email'=>'a@b.test','name'=>'Ana','consent'=>true,'website'=>'http://spam'], 'GUEST_INVALID'],
    [['email'=>'a@b.test','name'=>'Ana','consent'=>true,'password'=>'x'], 'GUEST_INVALID'],
    ['a@b.test', 'GUEST_INVALID'],
] as [$input, $code]) {
    guestRejects(fn() => brvtal_public_ticket_checkout_guest_input($input), $code, 'guest input ' . json_encode($input));
}
// Same-origin rule (no session, no CSRF token).
$_SERVER['HTTP_HOST'] = 'www.brvtal.com.co';
$_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
guestAssert(brvtal_public_ticket_checkout_same_origin(), 'same-origin fetch accepted');
$_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
guestAssert(!brvtal_public_ticket_checkout_same_origin(), 'cross-site fetch rejected');
unset($_SERVER['HTTP_SEC_FETCH_SITE']);
$_SERVER['HTTP_ORIGIN'] = 'https://www.brvtal.com.co';
guestAssert(brvtal_public_ticket_checkout_same_origin(), 'own Origin accepted when Sec-Fetch-Site is absent');
$_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
guestAssert(!brvtal_public_ticket_checkout_same_origin(), 'foreign Origin rejected');
unset($_SERVER['HTTP_ORIGIN']);
guestAssert(!brvtal_public_ticket_checkout_same_origin(), 'no origin information is rejected');

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL #1943 guest input and same-origin rules passed; MariaDB checks need BRVTAL_INTEGRATION_TESTS=1.\n";
    exit(0);
}
$base = (string)getenv('BRVTAL_TEST_DB_NAME');
guestAssert((bool)preg_match('/^brvtal_test[A-Za-z0-9_]*$/D', $base), 'disposable DB namespace');
$host = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$opt = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false];
$server = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, $opt);
$name = $base . '_guest_' . bin2hex(random_bytes(3));
$server->exec("CREATE DATABASE $name");
register_shutdown_function(static function () use ($server, $name): void {
    try { $server->exec("DROP DATABASE IF EXISTS $name"); } catch (Throwable) {}
});
$db = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, $opt);
foreach ([
    "CREATE TABLE ticket_buyers (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_id BINARY(16) NOT NULL,
        email VARCHAR(190) NULL,full_name VARCHAR(120) NULL,password_hash VARCHAR(255) NULL,email_verified_at DATETIME(6) NULL,
        security_epoch BIGINT UNSIGNED NOT NULL DEFAULT 1,
        consent_version VARCHAR(40) NULL,consent_recorded_at DATETIME(6) NULL,
        status ENUM('guest','active','blocked') NOT NULL DEFAULT 'guest',UNIQUE KEY uq_email(email)) ENGINE=InnoDB",
    "CREATE TABLE ticket_buyer_sessions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        buyer_id BIGINT UNSIGNED NOT NULL,session_hash CHAR(64) NOT NULL UNIQUE,
        issued_epoch BIGINT UNSIGNED NOT NULL,expires_at_utc DATETIME(6) NOT NULL,
        revoked_at_utc DATETIME(6) NULL) ENGINE=InnoDB",
    "CREATE TABLE events (id INT UNSIGNED PRIMARY KEY,status VARCHAR(32),event_date DATETIME,published_at DATETIME)",
    "CREATE TABLE event_ticket_types (id INT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED,name VARCHAR(120) NOT NULL,
        price INT UNSIGNED NOT NULL,sort_order INT NOT NULL DEFAULT 0,status VARCHAR(32),currency VARCHAR(3),
        available_from DATETIME,available_until DATETIME)",
    "CREATE TABLE ticket_inventory_events (event_id INT UNSIGNED PRIMARY KEY,capacity INT UNSIGNED)",
    "CREATE TABLE ticket_inventory_types (ticket_type_id INT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED,capacity INT UNSIGNED,
        courtesy_capacity INT UNSIGNED DEFAULT 0)",
    "CREATE TABLE ticket_orders (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,event_id INT UNSIGNED,buyer_id BIGINT UNSIGNED,
        status VARCHAR(20),public_id BINARY(16),checkout_key_hash CHAR(64) UNIQUE,currency CHAR(3),total_cop BIGINT UNSIGNED,
        expires_at_utc DATETIME(6),updated_at_utc DATETIME(6))",
    "CREATE TABLE ticket_order_items (order_id BIGINT UNSIGNED,ticket_type_id INT UNSIGNED,quantity INT,event_id INT UNSIGNED,
        ticket_name_snapshot VARCHAR(120),unit_price_cop BIGINT UNSIGNED,subtotal_cop BIGINT UNSIGNED,currency CHAR(3))",
    "CREATE TABLE ticket_inventory_holds (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED,event_id INT UNSIGNED,
        ticket_type_id INT UNSIGNED,quantity INT,kind VARCHAR(20),status VARCHAR(20),expires_at_utc DATETIME(6),
        UNIQUE KEY uq_hold(order_id,ticket_type_id))",
    "CREATE TABLE ticket_payment_attempts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED,provider VARCHAR(24),
        provider_reference VARCHAR(190),idempotency_key_hash CHAR(64),gross_amount_cop BIGINT UNSIGNED,status VARCHAR(20))",
] as $ddl) $db->exec($ddl);
foreach (['BRVTAL_TICKET_WOMPI_MODE=mock','BRVTAL_TICKET_WOMPI_TESTING=1','BRVTAL_TICKET_WOMPI_PUBLIC_KEY=pub_test_guest_fixture',
    'BRVTAL_TICKET_WOMPI_PRIVATE_KEY=prv_test_guest_fixture','BRVTAL_TICKET_WOMPI_INTEGRITY_SECRET=test_integrity_guest_fixture',
    'BRVTAL_TICKET_WOMPI_ENABLED_METHODS=CARD','BRVTAL_TICKET_WOMPI_RETURN_URL=https://example.test/return'] as $setting) putenv($setting);
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$db->prepare("INSERT INTO events VALUES (1,'published',?,?),(2,'published',?,?)")->execute([
    $now->modify('+5 days')->format('Y-m-d H:i:s'), $now->modify('-1 day')->format('Y-m-d H:i:s'),
    $now->modify('+5 days')->format('Y-m-d H:i:s'), $now->modify('-1 day')->format('Y-m-d H:i:s')]);
$db->exec("INSERT INTO event_ticket_types(id,event_id,name,price,status,currency) VALUES (10,1,'Preventa',25000,'active','COP')");
$db->exec('INSERT INTO ticket_inventory_events VALUES (1,50),(2,50)');
$db->exec('INSERT INTO ticket_inventory_types(ticket_type_id,event_id,capacity) VALUES (10,1,20)');

// New email: a guest buyer with name and consent.
$guestId = brvtal_public_ticket_checkout_guest_buyer($db, ['email'=>'nueva@example.test', 'name'=>'Nueva Persona']);
$row = $db->query("SELECT status,full_name,consent_version,consent_recorded_at,password_hash FROM ticket_buyers WHERE id=$guestId")->fetch();
guestAssert($row['status'] === 'guest' && $row['full_name'] === 'Nueva Persona' && $row['password_hash'] === null, 'guest created');
guestAssert($row['consent_version'] === 'checkout-v1' && $row['consent_recorded_at'] !== null, 'consent recorded');
guestAssert(brvtal_public_ticket_checkout_guest_buyer($db, ['email'=>'nueva@example.test', 'name'=>'Otra']) === $guestId,
    'same email reuses the same buyer');

// Existing account: reused without password; empty name filled, existing name kept.
$db->exec("INSERT INTO ticket_buyers(public_id,email,full_name,password_hash,status) VALUES (RANDOM_BYTES(16),'socia@example.test',NULL,'hash','active'),
    (RANDOM_BYTES(16),'bloqueada@example.test','B','hash','blocked')");
$memberId = (int)$db->query("SELECT id FROM ticket_buyers WHERE email='socia@example.test'")->fetchColumn();
guestAssert(brvtal_public_ticket_checkout_guest_buyer($db, ['email'=>'socia@example.test', 'name'=>'Socia Real']) === $memberId,
    'existing account reused');
guestAssert($db->query("SELECT full_name FROM ticket_buyers WHERE id=$memberId")->fetchColumn() === 'Socia Real', 'missing name filled');
guestAssert($db->query("SELECT status FROM ticket_buyers WHERE id=$memberId")->fetchColumn() === 'active', 'account status untouched');
$buyersBefore = (int)$db->query('SELECT COUNT(*) FROM ticket_buyers')->fetchColumn();
guestRejects(fn() => brvtal_public_ticket_checkout_guest_buyer($db, ['email'=>'bloqueada@example.test', 'name'=>'X Y']),
    'BUYER_BLOCKED', 'blocked account');
guestAssert((int)$db->query('SELECT COUNT(*) FROM ticket_buyers')->fetchColumn() === $buyersBefore, 'no buyer created on rejection');

// A guest order end to end, then status by its opaque token.
$result = brvtal_public_ticket_checkout_prepare($db, 1, $guestId,
    ['lines'=>[['ticket_type_id'=>10,'quantity'=>2]], 'idempotency_key'=>str_repeat('g', 20), 'method'=>'CARD']);
$token = brvtal_public_ticket_checkout_order_token($db, (int)$result['order_id']);
guestAssert(preg_match('/^[a-f0-9]{32}$/D', $token) === 1, 'opaque 32-hex order token');
guestAssert((int)$db->query('SELECT buyer_id FROM ticket_orders WHERE id=' . (int)$result['order_id'])->fetchColumn() === $guestId, 'order belongs to the guest');
guestAssert(brvtal_public_ticket_checkout_state_by_token($db, 1, $token) === 'pending', 'status by token');
$db->exec('UPDATE ticket_orders SET status=\'paid\' WHERE id=' . (int)$result['order_id']);
guestAssert(brvtal_public_ticket_checkout_state_by_token($db, 1, $token) === 'approved', 'paid order reads approved');
guestAssert(brvtal_public_ticket_checkout_state_by_token($db, 2, $token) === 'unverified', 'token is bound to its event');
guestAssert(brvtal_public_ticket_checkout_state_by_token($db, 1, str_repeat('0', 32)) === 'unverified', 'unknown token reveals nothing');
guestAssert(brvtal_public_ticket_checkout_state_by_token($db, 1, "' OR 1=1 --") === 'unverified', 'malformed token rejected');


// BRVTAL #1988: grants are browser-session capabilities, not order-URL auth.
// Only paid purchases in the original cookie may be upgraded in phase B.
brvtalBuyerSessionStart();
guestAssert(brvtalBuyerCheckoutPaidGrant($db, $token) === null,
    'bearer order URL alone cannot authorize the buyer');
brvtalBuyerCheckoutRememberOrder($guestId, (int)$result['order_id'], true);
$newGrant = brvtalBuyerCheckoutPaidGrant($db, $token);
guestAssert($newGrant === [
    'order_id'=>(int)$result['order_id'], 'buyer_id'=>$guestId, 'created_new'=>true,
], 'paid newly created guest has only its own browser grant');
guestAssert(brvtalBuyerCheckoutPaidGrant($db, str_repeat('0', 32)) === null,
    'unknown token does not borrow an existing browser grant');
guestAssert(brvtalBuyerCheckoutPaidGrant($db, "' OR 1=1 --") === null,
    'injection-shaped token does not authorize checkout');

$originalGrants = $_SESSION['buyer_checkout_orders'];
$_SESSION['buyer_checkout_orders'] = []; // Second browser: same URL, no originating cookie.
guestAssert(brvtalBuyerCheckoutPaidGrant($db, $token) === null,
    'second browser with bearer URL never gains session');
$_SESSION['buyer_checkout_orders'] = $originalGrants;
$_SESSION['buyer_checkout_orders'][(string)$result['order_id']]['buyer_id'] = $memberId;
guestAssert(brvtalBuyerCheckoutPaidGrant($db, $token) === null,
    'grant with another buyer ID is rejected');
$_SESSION['buyer_checkout_orders'] = $originalGrants;
$_SESSION['buyer_checkout_orders'][(string)$result['order_id']]['issued_at'] -=
    BRVTAL_BUYER_CHECKOUT_GRANT_SECONDS + 1;
guestAssert(brvtalBuyerCheckoutPaidGrant($db, $token) === null,
    'expired browser proof cannot elevate purchase');
$_SESSION['buyer_checkout_orders'] = $originalGrants;

$db->exec("INSERT INTO ticket_orders(event_id,buyer_id,status,public_id,checkout_key_hash,currency,total_cop)
    VALUES (1,$memberId,'paid',RANDOM_BYTES(16),SHA2('existing-buyer-order',256),'COP',30000)");
$existingOrder = (int)$db->lastInsertId();
$existingToken = brvtal_public_ticket_checkout_order_token($db, $existingOrder);
brvtalBuyerCheckoutRememberOrder($memberId, $existingOrder, false);
$existingGrant = brvtalBuyerCheckoutPaidGrant($db, $existingToken);
guestAssert($existingGrant === [
    'order_id'=>$existingOrder, 'buyer_id'=>$memberId, 'created_new'=>false,
], 'existing account gets only the purchased order, never full account login');
guestAssert((int)($_SESSION['buyer_id'] ?? 0) === 0,
    'checkout grant does not authenticate any existing account');
// Existing email, even with a paid checkout from this browser, can NEVER
// acquire an authenticated session without its verified password.
$existingLoginRejected = false;
try {
    brvtalBuyerLoginSession($memberId, $db, $existingOrder);
} catch (RuntimeException $error) {
    $existingLoginRejected = $error->getMessage() === 'BUYER_SESSION_NOT_ELIGIBLE';
}
guestAssert($existingLoginRejected, 'existing email cannot auto-login');
guestAssert((int)($_SESSION['buyer_id'] ?? 0) === 0, 'existing account stayed signed out');

// New email, paid order and the ORIGINAL checkout session => real login.
// Still do not mark email_verified_at; payment is not proof of inbox control.
brvtalBuyerLoginSession($guestId, $db, (int)$result['order_id']);
guestAssert((int)($_SESSION['buyer_id'] ?? 0) === $guestId
    && (int)($_SESSION['buyer_checkout_new_order'] ?? 0) === (int)$result['order_id'],
    'fresh checkout grants a real, provenance-bound buyer session');
guestAssert(brvtalBuyerAccountAuthorized($db, $guestId),
    'only newly created paid buyer can see its account orders');
guestAssert(!brvtalBuyerAccountAuthorized($db, $memberId),
    'a second buyer account cannot be viewed through this session');
guestAssert($db->query("SELECT email_verified_at FROM ticket_buyers WHERE id=$guestId")->fetchColumn() === null,
    'a paid checkout never falsely verifies email ownership');
guestAssert((int)$db->query("SELECT COUNT(*) FROM ticket_buyer_sessions WHERE buyer_id=$guestId")->fetchColumn() === 1,
    'new buyer login has a persisted revocable session');

$guestEpoch = (int)$db->query("SELECT security_epoch FROM ticket_buyers WHERE id=$guestId")->fetchColumn();
$guestSession = $db->prepare('SELECT issued_epoch FROM ticket_buyer_sessions
    WHERE buyer_id=? AND session_hash=?');
$guestSession->execute([$guestId, hash('sha256', session_id())]);
guestAssert((int)$guestSession->fetchColumn() === $guestEpoch,
    'guest session persists its own epoch without password proof');
unset($_SESSION['buyer_checkout_orders']);

echo "BRVTAL #1943 guest checkout: input, same-origin, guest buyer, reuse, blocked, order and token status passed.\n";
