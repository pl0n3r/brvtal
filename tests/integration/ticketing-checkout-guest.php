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
// #2231: reject invalid dates and inverted civil ranges before SQL.
foreach ([
    ['from'=>'2026-02-29'], ['to'=>'2026-04-31'], ['from'=>'2026-8-05'],
    ['from'=>['2026-08-05']], ['from'=>'2026-08-06','to'=>'2026-08-05'],
] as $invalidPeriod) {
    guestRejects(fn()=>brvtalBuyerAccountFilters($invalidPeriod),'FILTER_INVALID','invalid calendar filter');
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
    "CREATE TABLE events (id INT UNSIGNED PRIMARY KEY,status VARCHAR(32),event_date DATETIME,published_at DATETIME,title VARCHAR(180),venue VARCHAR(180))",
    "CREATE TABLE event_ticket_types (id INT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED,name VARCHAR(120) NOT NULL,
        price INT UNSIGNED NOT NULL,sort_order INT NOT NULL DEFAULT 0,status VARCHAR(32),currency VARCHAR(3),
        available_from DATETIME,available_until DATETIME)",
    "CREATE TABLE ticket_inventory_events (event_id INT UNSIGNED PRIMARY KEY,capacity INT UNSIGNED)",
    "CREATE TABLE ticket_inventory_types (ticket_type_id INT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED,capacity INT UNSIGNED,
        courtesy_capacity INT UNSIGNED DEFAULT 0)",
    "CREATE TABLE ticket_orders (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,event_id INT UNSIGNED,buyer_id BIGINT UNSIGNED,
        status VARCHAR(20),public_id BINARY(16),checkout_key_hash CHAR(64) UNIQUE,currency CHAR(3),total_cop BIGINT UNSIGNED,
        expires_at_utc DATETIME(6),updated_at_utc DATETIME(6),created_at_utc DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6))",
    "CREATE TABLE ticket_order_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED,ticket_type_id INT UNSIGNED,quantity INT,event_id INT UNSIGNED,
        ticket_name_snapshot VARCHAR(120),unit_price_cop BIGINT UNSIGNED,subtotal_cop BIGINT UNSIGNED,currency CHAR(3))",
    "CREATE TABLE ticket_entries (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_item_id BIGINT UNSIGNED,
        public_id BINARY(16),status VARCHAR(20),unit_number INT,event_id INT UNSIGNED,ticket_type_id INT UNSIGNED)",
    "CREATE TABLE ticket_entry_credentials (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,entry_id BIGINT UNSIGNED,status VARCHAR(20),version INT,token_hash BINARY(32),token_encrypted VARBINARY(61))",
    "CREATE TABLE ticket_issue_outbox (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED,state VARCHAR(20),payment_attempt_id BIGINT UNSIGNED)",
    "CREATE TABLE ticket_inventory_holds (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED,event_id INT UNSIGNED,
        ticket_type_id INT UNSIGNED,quantity INT,kind VARCHAR(20),status VARCHAR(20),expires_at_utc DATETIME(6),
        UNIQUE KEY uq_hold(order_id,ticket_type_id))",
    "CREATE TABLE ticket_payment_attempts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED,provider VARCHAR(24),
        provider_reference VARCHAR(190),idempotency_key_hash CHAR(64),gross_amount_cop BIGINT UNSIGNED,status VARCHAR(20),
        updated_at_utc DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6))",
] as $ddl) $db->exec($ddl);
foreach (['BRVTAL_TICKET_WOMPI_MODE=mock','BRVTAL_TICKET_WOMPI_TESTING=1','BRVTAL_TICKET_WOMPI_PUBLIC_KEY=pub_test_guest_fixture',
    'BRVTAL_TICKET_WOMPI_PRIVATE_KEY=prv_test_guest_fixture','BRVTAL_TICKET_WOMPI_INTEGRITY_SECRET=test_integrity_guest_fixture',
    'BRVTAL_TICKET_WOMPI_ENABLED_METHODS=CARD','BRVTAL_TICKET_WOMPI_RETURN_URL=https://example.test/return'] as $setting) putenv($setting);
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$db->prepare("INSERT INTO events(id,status,event_date,published_at,title,venue) VALUES (1,'published',?,?,'Noche de prueba','Lugar sintético'),(2,'published',?,?,'Otro evento',NULL)")->execute([
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
$httpDirectory = sys_get_temp_dir() . '/brvtal-2113-' . bin2hex(random_bytes(8));
guestAssert(mkdir($httpDirectory, 0700), 'create isolated HTTP fixture directory');
register_shutdown_function(static function () use ($httpDirectory): void {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    // Never follow symlinks or traverse outside the private fixture root.
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($httpDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($entries as $entry) {
        $path = $entry->getPathname();
        if ($entry->isLink() || !$entry->isDir()) {
            unlink($path);
        } else {
            rmdir($path);
        }
    }
    rmdir($httpDirectory);
});
session_save_path($httpDirectory);
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

// A deterministic INSERT failure must preserve only browser checkout provenance.
$grantsBeforeFailure = $_SESSION['buyer_checkout_orders'];
$db->exec("CREATE TRIGGER guest_session_insert_failure BEFORE INSERT ON ticket_buyer_sessions
    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='SYNTHETIC_SESSION_FAILURE'");
$insertRejected = false;
try {
    brvtalBuyerLoginSession($guestId, $db, (int)$result['order_id']);
} catch (PDOException $error) {
    $insertRejected = (string)$error->getCode() === '45000';
} finally {
    $db->exec('DROP TRIGGER guest_session_insert_failure');
}
guestAssert($insertRejected && !$db->inTransaction(), 'failed guest session INSERT rolls back');
guestAssert($_SESSION === ['buyer_checkout_orders'=>$grantsBeforeFailure],
    'failure keeps only original checkout provenance, not authenticated identity');
guestAssert((int)$db->query("SELECT COUNT(*) FROM ticket_buyer_sessions WHERE buyer_id=$guestId")->fetchColumn() === 0,
    'failed guest session has no persisted identity');
guestAssert(brvtalBuyerCheckoutPaidGrant($db, $token) === $newGrant,
    'same original browser can retry the paid guest login');

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
// #2113: a second order of the same guest is not within the paid grant.
$paidOrder = (int)$result['order_id'];
$db->exec("INSERT INTO ticket_orders(event_id,buyer_id,status,public_id,checkout_key_hash,currency,total_cop)
    VALUES (1,$guestId,'pending',RANDOM_BYTES(16),SHA2('scope-other-guest-order',256),'COP',30000)");
$otherGuestOrder = (int)$db->lastInsertId();
$otherGuestToken = brvtal_public_ticket_checkout_order_token($db, $otherGuestOrder);
$probe = new class("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, $opt) extends PDO {
    public int $providerQueries = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, 'ticket_payment_attempts')) {
            ++$this->providerQueries;
        }
        return parent::prepare($query, $options);
    }
};
guestAssert(brvtal_public_ticket_checkout_state($probe, 1, $guestId, $paidOrder) === 'approved',
    'guest can query only its own paid grant by number');
foreach ([$otherGuestOrder, $existingOrder, 999999] as $deniedOrder) {
    guestAssert(brvtal_public_ticket_checkout_state($probe, 1, $guestId, $deniedOrder) === 'unverified',
        'outside grant and unknown order are indistinguishable');
}
guestAssert(brvtal_public_ticket_checkout_state($probe, 1, $memberId, $existingOrder) === 'unverified',
    'buyer parameter cannot substitute another authenticated session');
guestAssert($probe->providerQueries === 0, 'no reconciliation lookup outside guest scope');
$guestState = $_SESSION;
unset($_SESSION['buyer_checkout_new_order']);
guestAssert(brvtal_public_ticket_checkout_state($probe, 1, $guestId, $paidOrder) === 'unverified',
    'guest without server-issued scope cannot query order');
$_SESSION = $guestState;
guestAssert(brvtal_public_ticket_checkout_state_by_token($probe, 1, $otherGuestToken) === 'pending',
    'opaque token remains a separate confirmation capability');
guestAssert($probe->providerQueries === 1, 'authorized pending token reaches reconciliation lookup');
$db->exec("UPDATE ticket_buyers SET email_verified_at=UTC_TIMESTAMP(6) WHERE id=$memberId");
$_SESSION = ['buyer_id'=>$memberId];
guestAssert(brvtal_public_ticket_checkout_state($probe, 1, $memberId, $existingOrder) === 'approved',
    'verified account retains access to its own order');
$filters = brvtalBuyerAccountFilters([]);
$memberOrders = brvtalBuyerAccountOrders($db, $memberId, $filters)['orders'];
guestAssert(count($memberOrders) === 1 && (int)$memberOrders[0]['order_number'] === $existingOrder
    && $memberOrders[0]['event_title'] === 'Noche de prueba' && $memberOrders[0]['venue'] === 'Lugar sintético'
    && $memberOrders[0]['event_date'] === $db->query('SELECT event_date FROM events WHERE id=1')->fetchColumn(),
    'authenticated account returns event metadata only for its own purchase');
guestAssert(!in_array($paidOrder, array_column($memberOrders, 'order_number'), true),
    'other buyer order metadata is not returned');
guestAssert(brvtal_public_ticket_checkout_state($probe, 1, $memberId, $paidOrder) === 'unverified',
    'verified account cannot see other buyers');
$_SESSION = $guestState;
$guestOrders = brvtalBuyerAccountOrders($db, $guestId, $filters)['orders'];
guestAssert(count($guestOrders) === 1 && (int)$guestOrders[0]['order_number'] === $paidOrder
    && $guestOrders[0]['venue'] === 'Lugar sintético' && $guestOrders[0]['event_title'] === 'Noche de prueba',
    'guest account metadata remains limited to its paid grant');

// Exercise the actual checkout HTTP handler, session epoch revalidation and
// JSON response over loopback, not a simulated HTTP response or live provider.
$guestCookie = session_id();
session_write_close();
session_id('');
brvtalBuyerSessionStart();
$memberEpoch = (int)$db->query("SELECT security_epoch FROM ticket_buyers WHERE id=$memberId")->fetchColumn();
brvtalBuyerLoginSession($memberId, $db, null, $memberEpoch);
// #2187: receipts are not financial orders, and require a real verified session.
$db->exec("CREATE TABLE ticket_courtesy_invites (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,event_id INT UNSIGNED,ticket_type_id INT UNSIGNED,
    order_id BIGINT UNSIGNED,entry_id BIGINT UNSIGNED UNIQUE,origin VARCHAR(20) DEFAULT 'courtesy',
    recipient_digest CHAR(64),status VARCHAR(20),resend_requests INT DEFAULT 0)");
$GLOBALS['config']['security']['encryption_key'] = bin2hex(random_bytes(32));
$makeCourtesy = static function(int $buyer, string $email) use ($db): array {
    $db->prepare("INSERT INTO ticket_orders(event_id,buyer_id,status,public_id,checkout_key_hash,currency,total_cop)
        VALUES(1,?,'draft',RANDOM_BYTES(16),?,'COP',0)")->execute([$buyer,hash('sha256',random_bytes(32))]);
    $order=(int)$db->lastInsertId();
    $db->exec("INSERT INTO ticket_order_items(order_id,event_id,ticket_type_id,quantity,ticket_name_snapshot,unit_price_cop,subtotal_cop,currency)
        VALUES($order,1,10,1,'Cortesía',0,0,'COP')");
    $item=(int)$db->lastInsertId();
    $public=random_bytes(16); $raw=random_bytes(32);
    $db->prepare("INSERT INTO ticket_entries(order_item_id,public_id,status,unit_number,event_id,ticket_type_id)
        VALUES(?,?,'issued',1,1,10)")->execute([$item,$public]);
    $entry=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO ticket_entry_credentials(entry_id,status,version,token_hash,token_encrypted)
        VALUES(?,'active',1,?,?)")->execute([$entry,hash('sha256',$raw,true),brvtal_ticket_issue_seal_token($raw,$public)]);
    $db->exec("INSERT INTO ticket_inventory_holds(order_id,event_id,ticket_type_id,quantity,kind,status,expires_at_utc)
        VALUES($order,1,10,1,'courtesy','consumed',UTC_TIMESTAMP(6))");
    $db->prepare("INSERT INTO ticket_courtesy_invites(event_id,ticket_type_id,order_id,entry_id,recipient_digest,status)
        VALUES(1,10,?,?,?,'issued')")->execute([$order,$entry,hash('sha256',$email)]);
    return [$order,$entry,bin2hex($public),rtrim(strtr(base64_encode($raw),'+/','-_'),'=')];
};
[$courtesyOrder,$courtesyEntry,$courtesyTicket,$courtesyToken]=$makeCourtesy($memberId,'socia@example.test');
[$foreignOrder,$foreignEntry,$foreignTicket,$foreignToken]=$makeCourtesy($guestId,'nueva@example.test');
putenv('BRVTAL_TICKET_COURTESY_ACCOUNT_ENABLED');
$disabled=brvtalBuyerAccountOrders($db,$memberId,$filters);
guestAssert($disabled['courtesies']===[] && array_column($disabled['orders'],'order_number')===[$existingOrder],
    'pre-live default denies new courtesy access without misclassifying purchases');
guestRejects(fn()=>brvtalBuyerAccountStoredQr($db,$memberId,$courtesyTicket),'TICKET_UNAVAILABLE','default off stored QR');
guestRejects(fn()=>brvtalBuyerAccountQr($db,$memberId,$courtesyTicket,$courtesyToken),'TICKET_UNAVAILABLE','default off original QR');
putenv('BRVTAL_TICKET_COURTESY_ACCOUNT_ENABLED=1');
$view=brvtalBuyerAccountOrders($db,$memberId,$filters);
guestAssert(array_column($view['orders'],'order_number')===[$existingOrder]
    && count($view['courtesies'])===1 && !$view['courtesies_has_more'], 'courtesy separate from purchase ledger');
$c=$view['courtesies'][0];
guestAssert($c['origin']==='courtesy' && $c['ticket_id']===$courtesyTicket && $c['status']==='issued'
    && $c['qr_stored'] && !$c['refundable'] && array_intersect(array_keys($c), ['payment_status','total_cop','token_hash','token_encrypted'])===[],
    'authorized receipt has safe projection, no payment or secret');
$expected='BRVTAL:T1:'.$courtesyTicket.':'.$courtesyToken;
guestAssert(brvtalBuyerAccountStoredQr($db,$memberId,$courtesyTicket)['content']===$expected
    && brvtalBuyerAccountQr($db,$memberId,$courtesyTicket,$courtesyToken)['content']===$expected,
    'stored and original recovery return the same credential');
guestRejects(fn()=>brvtalBuyerAccountStoredQr($db,$memberId,$foreignTicket),'TICKET_UNAVAILABLE','foreign courtesy');
guestRejects(fn()=>brvtalBuyerAccountQr($db,$memberId,$foreignTicket,$foreignToken),'TICKET_UNAVAILABLE','foreign token');
guestAssert(brvtalBuyerAccountOrders($db,$memberId,brvtalBuyerAccountFilters(['event_id'=>'2']))['courtesies']===[],
    'courtesy event filter');
// #2231: calendar dates are Bogotá civil days, never UTC calendar days.
$day = ['from'=>'2026-08-05','to'=>'2026-08-05'];
$range = ['from'=>'2026-08-05','to'=>'2026-08-06'];
$calendarSession = $_SESSION;
$db->beginTransaction();
try {
    $stamp = $db->prepare('UPDATE ticket_orders SET created_at_utc=? WHERE id=?');
    // Other buyer and same-guest ungranted order also satisfy the date filter.
    foreach ([$foreignOrder,$otherGuestOrder] as $id) $stamp->execute(['2026-08-05 05:00:00.000000',$id]);
    foreach ([
        ['2026-08-05 04:59:59.999999',false,false],
        ['2026-08-05 05:00:00.000000',true,true],
        ['2026-08-06 04:59:59.999999',true,true],
        ['2026-08-06 05:00:00.000000',false,true],
        ['2026-08-07 04:59:59.999999',false,true],
        ['2026-08-07 05:00:00.000000',false,false],
    ] as [$at,$inDay,$inRange]) {
        foreach ([$existingOrder,$courtesyOrder,$paidOrder] as $id) $stamp->execute([$at,$id]);
        foreach ([[$day,$inDay],[$range,$inRange]] as [$period,$included]) {
            $periodFilters = brvtalBuyerAccountFilters($period + ['event_id'=>'1']);
            $_SESSION = $calendarSession;
            $calendarResult = brvtalBuyerAccountOrders($db,$memberId,$periodFilters);
            guestAssert(array_column($calendarResult['orders'],'order_number') === ($included ? [$existingOrder] : [])
                && array_column($calendarResult['courtesies'],'ticket_id') === ($included ? [$courtesyTicket] : [])
                && !$calendarResult['has_more'] && !$calendarResult['courtesies_has_more'], 'Bogota member purchase and courtesy ' . $at);
            $_SESSION = $guestState;
            $calendarResult = brvtalBuyerAccountOrders($db,$guestId,$periodFilters);
            guestAssert(array_column($calendarResult['orders'],'order_number') === ($included ? [$paidOrder] : [])
                && $calendarResult['courtesies'] === [] && !$calendarResult['courtesies_has_more'], 'Bogota guest grant ' . $at);
        }
    }
    $_SESSION = $calendarSession;
    $calendarResult = brvtalBuyerAccountOrders($db,$memberId,brvtalBuyerAccountFilters(['from'=>'2026-08-07']));
    guestAssert(array_column($calendarResult['orders'],'order_number') === [$existingOrder]
        && array_column($calendarResult['courtesies'],'ticket_id') === [$courtesyTicket], 'Bogota lower bound only');
    $calendarResult = brvtalBuyerAccountOrders($db,$memberId,brvtalBuyerAccountFilters(['to'=>'2026-08-06']));
    guestAssert($calendarResult['orders'] === [] && $calendarResult['courtesies'] === [], 'Bogota upper bound only');
} finally {
    $_SESSION = $calendarSession;
    $db->rollBack();
}
$memberState=$_SESSION;
$_SESSION=$guestState;
guestAssert(brvtalBuyerAccountOrders($db,$guestId,$filters)['courtesies']===[], 'guest paid grant cannot list courtesies');
guestRejects(fn()=>brvtalBuyerAccountStoredQr($db,$guestId,$foreignTicket),'TICKET_UNAVAILABLE','guest own courtesy');
$_SESSION=$memberState;
$db->exec("UPDATE ticket_courtesy_invites SET recipient_digest=SHA2('other@example.test',256) WHERE entry_id=$courtesyEntry");
guestAssert(brvtalBuyerAccountOrders($db,$memberId,$filters)['courtesies']===[], 'recipient digest mismatch');
guestRejects(fn()=>brvtalBuyerAccountStoredQr($db,$memberId,$courtesyTicket),'TICKET_UNAVAILABLE','wrong recipient digest');
$db->exec("UPDATE ticket_courtesy_invites SET recipient_digest=SHA2('socia@example.test',256) WHERE entry_id=$courtesyEntry");
$db->exec("UPDATE ticket_inventory_holds SET status='released' WHERE order_id=$courtesyOrder");
guestRejects(fn()=>brvtalBuyerAccountStoredQr($db,$memberId,$courtesyTicket),'TICKET_UNAVAILABLE','missing consumed hold');
$db->exec("UPDATE ticket_inventory_holds SET status='consumed' WHERE order_id=$courtesyOrder");
$db->exec("UPDATE ticket_entry_credentials SET status='revoked' WHERE entry_id=$courtesyEntry");
$newRaw=random_bytes(32);
$db->prepare("INSERT INTO ticket_entry_credentials(entry_id,status,version,token_hash,token_encrypted)
    VALUES(?,'active',2,?,?)")->execute([$courtesyEntry,hash('sha256',$newRaw,true),
        brvtal_ticket_issue_seal_token($newRaw,hex2bin($courtesyTicket))]);
$db->exec("UPDATE ticket_courtesy_invites SET resend_requests=1 WHERE entry_id=$courtesyEntry");
guestRejects(fn()=>brvtalBuyerAccountQr($db,$memberId,$courtesyTicket,$courtesyToken),'TICKET_UNAVAILABLE','rotated token');
$newToken=rtrim(strtr(base64_encode($newRaw),'+/','-_'),'=');
guestAssert(brvtalBuyerAccountStoredQr($db,$memberId,$courtesyTicket)['content']==='BRVTAL:T1:'.$courtesyTicket.':'.$newToken,
    'recovery follows active rotated credential');
$sealed=brvtal_ticket_issue_seal_token($newRaw,hex2bin($courtesyTicket));
$db->exec("UPDATE ticket_entry_credentials SET token_encrypted=NULL WHERE entry_id=$courtesyEntry AND version=2");
guestAssert(!brvtalBuyerAccountOrders($db,$memberId,$filters)['courtesies'][0]['qr_stored'], 'legacy NULL has no recoverable QR');
guestRejects(fn()=>brvtalBuyerAccountStoredQr($db,$memberId,$courtesyTicket),'TICKET_UNAVAILABLE','legacy NULL recovery');
guestRejects(fn()=>brvtalBuyerAccountQr($db,$memberId,$courtesyTicket,$newToken),'TICKET_UNAVAILABLE','legacy NULL original path');
$db->exec("UPDATE ticket_entry_credentials SET token_encrypted=RANDOM_BYTES(61) WHERE entry_id=$courtesyEntry AND version=2");
guestRejects(fn()=>brvtalBuyerAccountStoredQr($db,$memberId,$courtesyTicket),'TICKET_UNAVAILABLE','corrupt ciphertext');
guestRejects(fn()=>brvtalBuyerAccountQr($db,$memberId,$courtesyTicket,$newToken),'TICKET_UNAVAILABLE','corrupt ciphertext original path');
$db->prepare("UPDATE ticket_entry_credentials SET token_encrypted=? WHERE entry_id=? AND version=2")
    ->execute([$sealed,$courtesyEntry]);
$db->exec("UPDATE ticket_entry_credentials SET token_hash=RANDOM_BYTES(32) WHERE entry_id=$courtesyEntry AND version=2");
guestRejects(fn()=>brvtalBuyerAccountStoredQr($db,$memberId,$courtesyTicket),'TICKET_UNAVAILABLE','ciphertext hash mismatch');
$db->prepare("UPDATE ticket_entry_credentials SET token_hash=? WHERE entry_id=? AND version=2")
    ->execute([hash('sha256',$newRaw,true),$courtesyEntry]);
$db->exec("UPDATE ticket_entries SET status='used' WHERE id=$courtesyEntry");
$db->exec("UPDATE ticket_courtesy_invites SET status='used' WHERE entry_id=$courtesyEntry");
$c=brvtalBuyerAccountOrders($db,$memberId,$filters)['courtesies'][0];
guestAssert($c['status']==='used' && !$c['qr_stored'] && !$c['qr_requires_original_token'], 'used receipt has no QR');
guestRejects(fn()=>brvtalBuyerAccountStoredQr($db,$memberId,$courtesyTicket),'TICKET_UNAVAILABLE','used courtesy');
$db->exec("UPDATE ticket_entries SET status='revoked' WHERE id=$courtesyEntry");
$db->exec("UPDATE ticket_courtesy_invites SET status='revoked' WHERE entry_id=$courtesyEntry");
$db->exec("UPDATE ticket_inventory_holds SET status='released' WHERE order_id=$courtesyOrder");
$c=brvtalBuyerAccountOrders($db,$memberId,$filters)['courtesies'][0];
guestAssert($c['status']==='revoked' && !$c['qr_stored'] && !$c['qr_requires_original_token'], 'revoked receipt has no QR');
guestRejects(fn()=>brvtalBuyerAccountStoredQr($db,$memberId,$courtesyTicket),'TICKET_UNAVAILABLE','revoked courtesy');
$db->exec("UPDATE ticket_entries SET status='issued' WHERE id=$courtesyEntry");
$db->exec("UPDATE ticket_courtesy_invites SET status='issued' WHERE entry_id=$courtesyEntry");
$db->exec("UPDATE ticket_inventory_holds SET status='consumed' WHERE order_id=$courtesyOrder");
$originalDate=$db->query("SELECT event_date FROM events WHERE id=1")->fetchColumn();
$db->exec("UPDATE events SET event_date=UTC_TIMESTAMP()-INTERVAL 3 DAY WHERE id=1");
guestRejects(fn()=>brvtalBuyerAccountStoredQr($db,$memberId,$courtesyTicket),'TICKET_UNAVAILABLE','expired courtesy');
guestAssert(brvtalBuyerAccountOrders($db,$memberId,$filters)['courtesies'][0]['status']==='expired','expired projection');
$db->prepare("UPDATE events SET event_date=? WHERE id=1")->execute([$originalDate]);
$db->prepare("UPDATE ticket_buyer_sessions SET expires_at_utc=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE session_hash=?")
    ->execute([hash('sha256',session_id())]);
guestAssert(brvtalBuyerAccountOrders($db,$memberId,$filters)['courtesies']===[], 'expired persisted session cannot list');
guestRejects(fn()=>brvtalBuyerAccountStoredQr($db,$memberId,$courtesyTicket),'TICKET_UNAVAILABLE','expired persisted session');
$db->prepare("UPDATE ticket_buyer_sessions SET expires_at_utc=UTC_TIMESTAMP(6)+INTERVAL 1 HOUR WHERE session_hash=?")
    ->execute([hash('sha256',session_id())]);
guestAssert((int)$db->query("SELECT COUNT(*) FROM ticket_payment_attempts WHERE order_id=$courtesyOrder")->fetchColumn()===0
    && (int)$db->query("SELECT COUNT(*) FROM ticket_entry_credentials WHERE entry_id=$courtesyEntry")->fetchColumn()===2,
    'recovery never pays, emits or rotates');
putenv('BRVTAL_TICKET_COURTESY_ACCOUNT_ENABLED');
echo "BRVTAL #2187 courtesy account scope, activation guard, rotation and read-only recovery passed.\n";
echo "BRVTAL #2231 Bogota calendar purchase/courtesy boundaries and guest isolation passed.\n";

$memberCookie = session_id();
session_write_close();
guestAssert($memberCookie !== $guestCookie, 'independent verified and guest cookies');

// Copy only reviewed source files, never local config, storage, or credentials.
$source = dirname(__DIR__, 2);
$fixtureRoot = $httpDirectory . '/site';
foreach (['/config', '/api/ticketing', '/storage', '/templates/ticketing'] as $directory) {
    guestAssert(mkdir($fixtureRoot . $directory, 0700, true), 'create private fixture subtree');
}
$fixtureSources = [
    'config/bootstrap.php', 'config/public_health.php', 'config/db_connect_recovery.php',
    'config/logger.php', 'config/sentry.php', 'config/deployment.php', 'config/version.php',
    'config/secrets.php', 'config/integration_secrets_store.php',
    'config/public_ticket_checkout.php', 'config/public_order_status.php',
    'config/public_contact.php', 'config/rate_limit_store.php', 'config/public_visibility.php',
    'config/ticketing_inventory.php', 'config/ticketing_wompi_client.php',
    'config/ticketing_stages.php', 'config/ticketing_waitlist.php', 'config/ticketing_promos.php',
    'config/buyer_account.php', 'config/buyer_auth.php', 'config/password_rate_limit.php',
    'config/ticketing_issue.php', 'config/ticketing_wompi_reconcile.php',
    'config/ticketing_wompi_webhook.php', 'config/ticketing_event_incident_policy.php',
    'config/ticketing_mail_outbox.php', 'config/ticketing_resend.php',
    'config/ticketing_mail_events.php', 'config/public_abuse_guard.php',
    'config/mail_template_renderer.php', 'config/mail_template_catalog.php',
    'config/buyer_auth_mail_templates.php', 'config/ticketing_staff_permissions.php',
    'config/ticketing_mail_templates.php', 'config/ticketing_mail_mode.php',
    'templates/ticketing/transactional.php', 'api/ticketing/order-status.php',
];
foreach ($fixtureSources as $relative) {
    $input = $source . '/' . $relative;
    guestAssert(is_file($input) && !is_link($input)
        && copy($input, $fixtureRoot . '/' . $relative), 'copy fixture source ' . $relative);
}
$fixtureConfig = [
    'security'=>['csrf_key'=>bin2hex(random_bytes(32))],
    'app'=>['timezone'=>'UTC','debug'=>false],
    'db'=>['host'=>$host,'port'=>$port,'name'=>$name,'user'=>$user,'pass'=>$pass,'charset'=>'utf8mb4'],
];
$configFile = $fixtureRoot . '/config/config.php';
guestAssert(file_put_contents($configFile, '<?php return ' . var_export($fixtureConfig, true) . ';') !== false,
    'write isolated configuration');
chmod($configFile, 0600);
$router = $httpDirectory . '/router.php';
$prefix = '<?php define("BRVTAL_SENTRY_TESTING", true); '
    . '$GLOBALS["brvtalBootstrapConfigPath"] = ' . var_export($configFile, true) . '; '
    . '$GLOBALS["brvtalSentryTestLogFile"] = ' . var_export($httpDirectory . '/fixture.log', true) . '; '
    . 'require ' . var_export($fixtureRoot . '/config/bootstrap.php', true) . '; '
    . 'require ' . var_export($fixtureRoot . '/config/public_ticket_checkout.php', true) . ';';
$routerCode = <<<'PHP'
if ($_SERVER['REQUEST_URI'] === '/ready') {
    http_response_code(204);
    exit;
}
// Loopback-only fixture models HTTPS termination; never trust a client header.
$_SERVER['HTTPS'] = 'on';
$settings = $GLOBALS['config']['db'];
$probe = new class(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $settings['host'], $settings['port'], $settings['name']), $settings['user'], $settings['pass'],
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]) extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, 'ticket_payment_attempts')) header('X-Fixture-Reconcile: yes');
        return parent::prepare($query, $options);
    }
};
brvtal_public_ticket_checkout_http($probe, 1);
PHP;
guestAssert(file_put_contents($router, $prefix . "\n" . $routerCode) !== false, 'write HTTP handler router');
chmod($router, 0600);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
guestAssert(is_resource($socket), 'allocate loopback port');
$address = stream_socket_get_name($socket, false);
fclose($socket);
guestAssert(is_string($address), 'loopback address');
$process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $httpDirectory,
    '-d', 'allow_url_fopen=0',
    '-d', 'disable_functions=curl_init,curl_exec,curl_multi_init,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client',
    '-S', $address, '-t', $fixtureRoot, $router],
    [0=>['pipe','r'],1=>['file','/dev/null','a'],2=>['file','/dev/null','a']], $pipes, $fixtureRoot);
guestAssert(is_resource($process), 'start isolated HTTP server');
fclose($pipes[0]);
try {
    $url = 'http://' . $address;
    $ready = false;
    for ($attempt = 0; $attempt < 30; ++$attempt) {
        $connection = @stream_socket_client('tcp://' . $address, $errno, $errstr, 0.2);
        if (is_resource($connection)) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    guestAssert($ready, 'HTTP fixture ready');
    $send = static function (array $query, ?string $cookie = null) use ($url): array {
        $headers = ['Connection: close', 'X-BRVTAL-Ticket-Checkout: status'];
        if ($cookie !== null) $headers[] = 'Cookie: BRVTAL_BUYER=' . $cookie;
        $context = stream_context_create(['http'=>['method'=>'GET','ignore_errors'=>true,
            'timeout'=>5,'header'=>implode("\r\n", $headers)]]);
        $response = fopen($url . '/?' . http_build_query($query), 'rb', false, $context);
        guestAssert(is_resource($response), 'HTTP response stream');
        try {
            $received = stream_get_meta_data($response)['wrapper_data'] ?? [];
            $body = stream_get_contents($response);
        } finally {
            fclose($response);
        }
        guestAssert(is_array($received), 'HTTP response headers');
        preg_match('~^HTTP/\S+ ([0-9]{3})~', (string)($received[0] ?? ''), $status);
        $normalized = array_map(strtolower(...), $received);
        guestAssert(in_array('cache-control: private, no-store', $normalized, true),
            'HTTP private no-store header');
        guestAssert(in_array('referrer-policy: no-referrer', $normalized, true),
            'HTTP no-referrer header');
        guestAssert(is_string($body), 'HTTP JSON body');
        return [(int)($status[1] ?? 0), json_decode($body, true, 512, JSON_THROW_ON_ERROR),
            in_array('x-fixture-reconcile: yes', $normalized, true)];
    };
    $approved = [200, ['ok'=>true,'state'=>'approved'], false];
    $denied = [200, ['ok'=>true,'state'=>'unverified'], false];
    $signedOut = [401, ['ok'=>false,'error'=>'AUTH_REQUIRED'], false];
    guestAssert($send(['order_id'=>$paidOrder], $guestCookie) === $approved, 'HTTP guest grant');
    foreach ([$otherGuestOrder, $existingOrder, 999999] as $deniedOrder) {
        guestAssert($send(['order_id'=>$deniedOrder], $guestCookie) === $denied,
            'HTTP uniform denial before reconciliation');
    }
    guestAssert($send(['order_id'=>$existingOrder], $memberCookie) === $approved,
        'HTTP verified buyer owns its order');
    guestAssert($send(['order_id'=>$paidOrder], $memberCookie) === $denied, 'HTTP different buyer denied');
    guestAssert($send(['order_id'=>$paidOrder]) === $signedOut, 'HTTP anonymous numeric request denied');
    guestAssert($send(['order_token'=>$otherGuestToken]) === [200,['ok'=>true,'state'=>'pending'],true],
        'HTTP opaque token remains independent and only authorized lookup reaches reconciliation');
    $db->prepare('UPDATE ticket_buyer_sessions SET expires_at_utc=UTC_TIMESTAMP(6) - INTERVAL 1 SECOND
        WHERE session_hash=?')->execute([hash('sha256', $guestCookie)]);
    guestAssert($send(['order_id'=>$paidOrder], $guestCookie) === $signedOut, 'HTTP expired grant session denied');
    guestAssert($send(['order_id'=>$otherGuestOrder], $guestCookie) === $signedOut,
        'HTTP expired session never reconciles pending order');
} finally {
    proc_terminate($process);
    proc_close($process);
}

echo "BRVTAL #2113 HTTP and MariaDB scope regression passed.\n";

echo "BRVTAL #1943 guest checkout: input, same-origin, guest buyer, reuse, blocked, order and token status passed.\n";
