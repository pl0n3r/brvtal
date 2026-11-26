<?php
declare(strict_types=1);
define('BRVTAL_T19_LIBRARY_ONLY',true);
require_once __DIR__ . '/../../api/ticketing/admin-entries.php';
function t19(bool $ok,string $msg):void {if (!$ok) throw new RuntimeException('T19: '.$msg);}
t19(brvtalTicketingCan('superadmin','reissue')&&!brvtalTicketingCan('door','reissue'),'RBAC');
if (getenv('BRVTAL_INTEGRATION_TESTS')!=='1') {echo "T19 roles OK; DB fixture skipped.\n";exit;}
putenv('BRVTAL_TICKET_MAIL_TESTING=1');
$prefix=(string)getenv('BRVTAL_TEST_DB_NAME');
t19((bool)preg_match('/^brvtal_test[A-Za-z0-9_]*$/D',$prefix),'disposable namespace');
$host=(string)getenv('BRVTAL_TEST_DB_HOST');$port=(int)(getenv('BRVTAL_TEST_DB_PORT')?:3306);
$user=(string)getenv('BRVTAL_TEST_DB_USER');$pass=(string)getenv('BRVTAL_TEST_DB_PASS');
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
$server=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",$user,$pass,$opts);
$name=$prefix.'_t19_'.bin2hex(random_bytes(4));
$server->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
try {
    $pdo=new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",$user,$pass,$opts);
    $sql=[
        "CREATE TABLE events(id INT UNSIGNED PRIMARY KEY,status VARCHAR(20)) ENGINE=InnoDB",
        "CREATE TABLE admins(id INT UNSIGNED PRIMARY KEY,staff_role VARCHAR(30),is_active TINYINT) ENGINE=InnoDB",
        "CREATE TABLE event_ticket_types(id INT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED) ENGINE=InnoDB",
        "CREATE TABLE ticket_buyers(id BIGINT UNSIGNED PRIMARY KEY,email VARCHAR(190),consent_version VARCHAR(40),consent_recorded_at DATETIME) ENGINE=InnoDB",
        "CREATE TABLE ticket_orders(id BIGINT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED,buyer_id BIGINT UNSIGNED,status VARCHAR(20)) ENGINE=InnoDB",
        "CREATE TABLE ticket_order_items(id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED,event_id INT UNSIGNED,ticket_type_id INT UNSIGNED) ENGINE=InnoDB",
        "CREATE TABLE ticket_issue_outbox(id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED,payment_attempt_id BIGINT UNSIGNED,state VARCHAR(20)) ENGINE=InnoDB",
        "CREATE TABLE ticket_payment_attempts(id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED,provider VARCHAR(20),status VARCHAR(20),provider_transaction_id VARCHAR(190)) ENGINE=InnoDB",
        "CREATE TABLE ticket_payment_webhook_events(id BIGINT UNSIGNED PRIMARY KEY,attempt_id BIGINT UNSIGNED,provider_transaction_id VARCHAR(190),signed_status VARCHAR(20)) ENGINE=InnoDB",
        "CREATE TABLE ticket_mail_outbox(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,message_key VARCHAR(128) UNIQUE,payload_hash CHAR(64),related_ref VARCHAR(128),recipient_email VARCHAR(190),subject VARCHAR(255),body_text TEXT,next_attempt_at DATETIME,created_at DATETIME,updated_at DATETIME,provider_message_id VARCHAR(128) NULL,state VARCHAR(20) DEFAULT 'queued') ENGINE=InnoDB",
        "CREATE TABLE ticket_mail_history(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,outbox_id BIGINT UNSIGNED UNIQUE,message_key VARCHAR(128),template_key VARCHAR(64),recipient_hint VARCHAR(190),recipient_digest CHAR(64),event_id INT,buyer_id BIGINT,order_id BIGINT,ticket_id BIGINT,actor_kind VARCHAR(12),resend_email_id VARCHAR(128),delivery_state VARCHAR(20),retention_until DATETIME,state_at DATETIME NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,redacted_at DATETIME NULL) ENGINE=InnoDB"
    ];foreach ($sql as $statement) $pdo->exec($statement);
    $ddl=preg_replace('/^\s*--.*$/m','',(string)file_get_contents(__DIR__.'/../../database/migration_ticketing_entries_01.sql'));
    foreach (array_filter(array_map(trim(...),explode(';',(string)$ddl))) as $statement) $pdo->exec($statement);
    foreach ([
        "INSERT INTO events VALUES(1,'active')",
        "INSERT INTO admins VALUES(7,'superadmin',1)",
        "INSERT INTO event_ticket_types VALUES(11,1)",
        "INSERT INTO ticket_buyers VALUES(3,'t19@example.test','fixture-v1','2026-11-21')",
        "INSERT INTO ticket_orders VALUES(100,1,3,'paid')",
        "INSERT INTO ticket_order_items VALUES(1,100,1,11)",
        "INSERT INTO ticket_issue_outbox VALUES(1,100,1,'done')",
        "INSERT INTO ticket_payment_attempts VALUES(1,100,'wompi','approved','txn-t19')",
        "INSERT INTO ticket_payment_webhook_events VALUES(1,1,'txn-t19','APPROVED')"
    ] as $statement) $pdo->exec($statement);
    $add=$pdo->prepare('INSERT INTO ticket_entries(id,public_id,order_item_id,event_id,ticket_type_id,unit_number,issued_at_utc) VALUES(?,?,1,1,11,?,UTC_TIMESTAMP(6))');
    $oldTokens=[];
    foreach ([1,2,3] as $id) {
        $public=random_bytes(16);$token=random_bytes(32);
        $add->execute([$id,$public,$id]);
        $pdo->prepare('INSERT INTO ticket_entry_credentials(entry_id,version,token_hash,created_at_utc) VALUES(?,1,?,UTC_TIMESTAMP(6))')
            ->execute([$id,hash('sha256',$token,true)]);
        $oldTokens[$id]='BRVTAL:T1:'.bin2hex($public).':'.rtrim(strtr(base64_encode($token),'+/','-_'),'=');
    }

    // Legacy schema must fail closed before revocation, audit or mail enqueue.
    $GLOBALS['config']['security']['encryption_key']=str_repeat('T',48);
    try {
        brvtalTicketAdminEntryMutation($pdo,1,7,'superadmin','reissue','lost','t19-no-column-fixture-aaaaaa');
        throw new RuntimeException('T19: reissue without encrypted column accepted');
    } catch (DomainException $error) {
        t19($error->getMessage()==='ISSUE_ENCRYPTION_UNAVAILABLE','missing ciphertext column denied');
    }
    t19((int)$pdo->query("SELECT COUNT(*) FROM ticket_entry_credentials WHERE entry_id=1 AND status='active'")->fetchColumn()===1,
        'missing-column failure preserves original credential');
    t19((int)$pdo->query('SELECT COUNT(*) FROM ticket_checkins')->fetchColumn()===0
        && (int)$pdo->query('SELECT COUNT(*) FROM ticket_mail_outbox')->fetchColumn()===0,
        'missing-column failure creates no audit or mail');

    // Apply the existing additive migration to the disposable MariaDB fixture.
    $migrate=file_get_contents(__DIR__.'/../../database/migration_ticket_entry_secret_01.sql');
    t19(is_string($migrate),'existing entry-secret migration available');
    $pdo->exec((string)preg_replace('/^\s*--[^\r\n]*$/m','',$migrate));
    unset($GLOBALS['config']['security']['encryption_key']);
    try {
        brvtalTicketAdminEntryMutation($pdo,1,7,'superadmin','reissue','lost','t19-no-key-fixture-aaaaaaaa');
        throw new RuntimeException('T19: reissue without master key accepted');
    } catch (DomainException $error) {
        t19($error->getMessage()==='ISSUE_ENCRYPTION_UNAVAILABLE','missing encryption key denied');
    }
    $GLOBALS['config']['security']['encryption_key']=str_repeat('T',48);
    t19((int)$pdo->query("SELECT COUNT(*) FROM ticket_entry_credentials WHERE entry_id=1 AND status='active'")->fetchColumn()===1,
        'missing-key failure preserves original credential');

    $r=brvtalTicketAdminEntryMutation($pdo,1,7,'superadmin','reissue','lost','t19-fixture-idempotency-aaaa');
    t19($r['qr']!==null&&!hash_equals($r['qr'],$oldTokens[1]),'new QR returned only once');
    $stored=$pdo->query("SELECT c.version,c.token_hash,c.token_encrypted,e.public_id
        FROM ticket_entry_credentials c JOIN ticket_entries e ON e.id=c.entry_id
        WHERE c.entry_id=1 AND c.status='active'")->fetch(PDO::FETCH_ASSOC);
    t19(is_array($stored) && (int)$stored['version']===2
        && is_string($stored['token_encrypted']) && strlen($stored['token_encrypted'])===61,
        'rotated version persisted with AES-GCM ciphertext');
    $newSecret=base64_decode(strtr(explode(':',$r['qr'])[3],'-_','+/').'=',true);
    t19(is_string($newSecret) && strlen($newSecret)===32
        && hash_equals($stored['token_hash'],hash('sha256',$newSecret,true))
        && hash_equals($newSecret,brvtal_ticket_issue_open_token($stored['token_encrypted'],$stored['public_id'])),
        'encrypted QR decrypts to the exact active credential digest');
    $savedCiphertext=$stored['token_encrypted'];
    t19(brvtalTicketCheckinConsume($pdo,1,7,'door-fixture',$oldTokens[1])['reason']==='credential_revoked','old QR revoked');
    t19(brvtalTicketCheckinConsume($pdo,1,7,'door-fixture',$r['qr'])['ok'],'new QR accepted');
    t19(brvtalTicketCheckinConsume($pdo,1,7,'door-fixture',$r['qr'])['reason']==='already_used','new QR one-use');
    $dup=brvtalTicketAdminEntryMutation($pdo,1,7,'superadmin','reissue','lost','t19-fixture-idempotency-aaaa');
    t19($dup['replayed']===true&&$dup['qr']===null,'double click cannot regenerate secret');
    t19($pdo->query("SELECT token_encrypted FROM ticket_entry_credentials WHERE entry_id=1 AND status='active'")->fetchColumn()===$savedCiphertext
        && (int)$pdo->query('SELECT COUNT(*) FROM ticket_entry_credentials WHERE entry_id=1')->fetchColumn()===2,
        'lost HTTP response replay retains exactly the same recoverable secret');
    t19((int)$pdo->query('SELECT COUNT(*) FROM ticket_mail_outbox')->fetchColumn()===1,'one notification, no token in outbox');
    $body=(string)$pdo->query('SELECT body_text FROM ticket_mail_outbox')->fetchColumn();
    t19(!str_contains($body,$r['qr']),'QR not persisted in notification');
    brvtalTicketAdminEntryMutation($pdo,2,7,'superadmin','void','compromised','t19-void-fixture-key-aaaaaa');
    t19($pdo->query('SELECT status FROM ticket_entries WHERE id=2')->fetchColumn()==='revoked','annulled entry');
    t19(brvtalTicketCheckinConsume($pdo,1,7,'door-fixture',$oldTokens[2])['reason']==='entry_revoked','annulled QR rejected');
    try {
        brvtalTicketAdminEntryMutation($pdo,3,7,'door','reissue','lost','t19-forbidden-operator-aaaaaa');
        throw new RuntimeException('T19: unauthorized operator reissued QR');
    } catch (DomainException $error) {
        t19($error->getMessage()==='FORBIDDEN','unauthorized role denied');
    }
    // Inject a mail queue failure while payment is still verified. Rollback
    // must restore the old active QR and remove all audit/notification effects.
    $pdo->exec("CREATE TRIGGER t19_fail_mail BEFORE INSERT ON ticket_mail_outbox
        FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='T19_QUEUE_FAILURE'");
    $queueFailed=false;
    try {
        brvtalTicketAdminEntryMutation($pdo,3,7,'superadmin','reissue','lost','t19-queue-failure-aaaaaaaaaa');
    } catch (PDOException) {
        $queueFailed=true;
    } finally {
        $pdo->exec('DROP TRIGGER t19_fail_mail');
    }
    t19($queueFailed,'mail enqueue failure propagates');
    t19((int)$pdo->query("SELECT COUNT(*) FROM ticket_entry_credentials WHERE entry_id=3 AND status='active'")->fetchColumn()===1
        && (int)$pdo->query('SELECT COUNT(*) FROM ticket_entry_credentials WHERE entry_id=3')->fetchColumn()===1,
        'queue failure rolls back revocation and replacement');
    t19((int)$pdo->query('SELECT COUNT(*) FROM ticket_mail_outbox')->fetchColumn()===1
        && (int)$pdo->query('SELECT COUNT(*) FROM ticket_mail_history')->fetchColumn()===1
        && (int)$pdo->query("SELECT COUNT(*) FROM ticket_checkins WHERE entry_id=3")->fetchColumn()===0,
        'queue failure leaves no new mail, history or audit');

    $pdo->exec("UPDATE ticket_payment_webhook_events SET signed_status='PENDING' WHERE id=1");
    try {brvtalTicketAdminEntryMutation($pdo,3,7,'superadmin','reissue','lost','t19-no-payment-fixture-aaaaaa');throw new RuntimeException('T19: pending accepted');}
    catch (DomainException $e) {t19($e->getMessage()==='PAYMENT_UNVERIFIED','signed payment gate');}
    t19($pdo->query('SELECT status FROM ticket_entries WHERE id=3')->fetchColumn()==='issued','failed reissue preserves ticket');
    t19((int)$pdo->query("SELECT COUNT(*) FROM ticket_checkins WHERE reason_code LIKE 'credential_reissued_%'")->fetchColumn()===1,'actor and reason audited');
} finally {$server->exec('DROP DATABASE IF EXISTS '.$name);}
echo "T19 reissue/void, Wompi guard, idempotency, mail audit and one-use QR passed.\n";
