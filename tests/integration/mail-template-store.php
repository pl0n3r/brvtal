<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/mail_template_store.php';

function mail_store_assert(bool $ok, string $reason): void {
    if (!$ok) throw new RuntimeException('MAIL STORE TEST FAILED: '.$reason);
}
function mail_store_reject(callable $test, string $reason): void {
    try { $test(); } catch (InvalidArgumentException|DomainException $error) { return; }
    throw new RuntimeException('MAIL STORE TEST FAILED: '.$reason);
}
$catalog = brvtal_mail_template_catalog();
mail_store_assert(count($catalog) === 10, '7 ticketing + 2 staff + global theme');
mail_store_assert(array_keys(require __DIR__ . '/../../templates/ticketing/transactional.php') ===
    array_slice(array_keys($catalog),0,7), 'no new transactional events');
$original = $catalog['order_pending']['defaults'];
$mail = brvtal_mail_template_validate('order_pending','ticketing',$original);
mail_store_assert($mail['subject'] === $original['subject'], 'allowed static copy');
mail_store_reject(static fn() => brvtal_mail_template_validate('unknown','ticketing',$mail),'unknown key');
mail_store_reject(static fn() => brvtal_mail_template_validate('order_pending','staff',$mail),'wrong scope');
$bad = $mail; $bad['title'] = '<script>alert(1)</script>';
mail_store_reject(static fn() => brvtal_mail_template_validate('order_pending','ticketing',$bad),'html');
$bad = $mail; $bad['message'] = 'https://evil.invalid';
mail_store_reject(static fn() => brvtal_mail_template_validate('order_pending','ticketing',$bad),'external url');
$bad = $mail; $bad['message'] = 'buyer@example.test';
mail_store_reject(static fn() => brvtal_mail_template_validate('order_pending','ticketing',$bad),'recipient pii');
$bad = $mail; $bad['message'] = '{{token}}';
mail_store_reject(static fn() => brvtal_mail_template_validate('order_pending','ticketing',$bad),'protected token');
$bad = $mail; $bad['message'] = 'Evento {{event_name}}';
mail_store_assert(brvtal_mail_template_validate('order_pending','ticketing',$bad)['message'] ===
    'Evento {{event_name}}', 'allowlisted event variable');
$bad = $mail; $bad['message'] = '{{event_name}}';
mail_store_reject(static fn() => brvtal_mail_template_validate('reset_password','ticketing',$bad),'no token-template variables');
$bad = $mail; $bad['theme']['accent'] = '#xxx';
mail_store_reject(static fn() => brvtal_mail_template_validate('order_pending','ticketing',$bad),'invalid hex');
$bad = $mail; $bad['theme']['logo_media_id'] = 55;
mail_store_reject(static fn() => brvtal_mail_template_validate('order_pending','ticketing',$bad),'unauthorized image');
mail_store_assert(brvtal_mail_template_validate('order_pending','ticketing',$bad,
    static fn(int $id) => $id === 55)['theme']['logo_media_id'] === 55, 'trusted Media resolver');
$bad = $mail; $bad['subject'] = str_repeat('X',181);
mail_store_reject(static fn() => brvtal_mail_template_validate('order_pending','ticketing',$bad),'field too long');
$bad = $mail; $bad['recipient_email'] = 'client@example.test';
mail_store_reject(static fn() => brvtal_mail_template_validate('order_pending','ticketing',$bad),'unknown PII field');
$bad = $mail; $bad['message'] = 'Bearer credential';
mail_store_reject(static fn() => brvtal_mail_template_validate('order_pending','ticketing',$bad),'secrets');
mail_store_assert(str_contains((string)file_get_contents(__DIR__.'/../../config/mail_template_store.php'),
    'MAIL_TEMPLATE_CONFLICT'), 'optimistic lock contract');

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL template catalog/validation standalone OK; MariaDB skipped.\n";
    return;
}
$base=(string)getenv('BRVTAL_TEST_DB_NAME');
mail_store_assert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',$base),'synthetic DB namespace');
$host=(string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port=(int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user=(string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass=(string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$name=$base.'_mail_templates_'.bin2hex(random_bytes(4));
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false];
$server=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$pass,$options);
$server->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
register_shutdown_function(static function () use ($server,$name): void {
    try { $server->exec('DROP DATABASE IF EXISTS '.$name); } catch (Throwable) {}
});
$pdo=new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",$user,$pass,$options);
mail_store_assert(brvtal_mail_template_resolve($pdo,'order_pending','ticketing')['source']==='catalog',
    'fallback before schema');
$schema=(string)file_get_contents(__DIR__.'/../../database/migration_mail_templates_01.sql');
foreach (range(1,2) as $iteration) {
    foreach (preg_split('/;\s*(?=CREATE|$)/i',$schema) ?: [] as $statement) {
        if (trim($statement)!=='') $pdo->exec($statement);
    }
}
mail_store_assert((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'mail_template_%'")->fetchColumn()===2,
    'empty/repeated migration');
mail_store_assert(brvtal_mail_template_resolve($pdo,'order_pending','ticketing')['source']==='catalog',
    'fallback without publication');
$first=brvtal_mail_template_save_draft($pdo,'order_pending','ticketing',$mail,7,0);
mail_store_assert($first['revision']===1 && $first['state']==='draft','draft create');
mail_store_assert(brvtal_mail_template_resolve($pdo,'order_pending','ticketing')['source']==='catalog',
    'saving never publishes');
mail_store_reject(static fn() => brvtal_mail_template_save_draft(
    $pdo,'order_pending','ticketing',$mail,8,0), 'optimistic concurrent conflict');
$p1=brvtal_mail_template_publish($pdo,'order_pending','ticketing',$first['version_id'],7,1);
mail_store_assert($p1['revision']===2,'publish CAS');
mail_store_assert(brvtal_mail_template_resolve($pdo,'order_pending','ticketing')['source']==='published',
    'publish exposed snapshot');
$mail['message']='Otro texto editorial seguro';
$second=brvtal_mail_template_save_draft($pdo,'order_pending','ticketing',$mail,7,2);
mail_store_assert(brvtal_mail_template_resolve($pdo,'order_pending','ticketing')['document']['message']!=='Otro texto editorial seguro',
    'draft isolated from published');
brvtal_mail_template_publish($pdo,'order_pending','ticketing',$second['version_id'],7,3);
$rollback=brvtal_mail_template_rollback($pdo,'order_pending','ticketing',$first['version_id'],8,4);
mail_store_assert($rollback['revision']===5
    && $rollback['version_id']!==$first['version_id'], 'rollback creates immutable snapshot');
mail_store_assert(brvtal_mail_template_resolve($pdo,'order_pending','ticketing')['document']['message']===
    $original['message'], 'rollback restores historical published content');
mail_store_reject(static fn() => brvtal_mail_template_rollback(
    $pdo,'order_pending','ticketing',$second['version_id'],7,4),'rollback CAS conflict');
$versions=brvtal_mail_template_versions($pdo,'order_pending','ticketing');
mail_store_assert(count($versions)===3 && !isset($versions[0]['document_json']),
    'metadata-only list');
mail_store_assert((int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'mail_template_%'
    AND COLUMN_NAME IN ('recipient','recipient_email','token','password','api_key')")->fetchColumn()===0,
    'no PII/secret columns');
mail_store_assert((int)$pdo->query('SELECT COUNT(*) FROM mail_template_versions')->fetchColumn()===3,
    'no duplicate side effects or sends');
echo "BRVTAL mail-template store synthetic MariaDB integration passed.\n";
