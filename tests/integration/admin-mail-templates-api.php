<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/mail_template_admin_api.php';

function admin_mail_check(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException('MAIL ADMIN TEST FAILED: '.$label);
}
function admin_mail_reject(callable $fn, string $label, string $exception = InvalidArgumentException::class): void {
    try { $fn(); } catch (Throwable $caught) {
        if ($caught instanceof $exception) return;
        throw $caught;
    }
    throw new RuntimeException('MAIL ADMIN TEST FAILED: '.$label);
}
$endpoint = (string)file_get_contents(__DIR__.'/../../api/admin-mail-templates.php');
admin_mail_check(str_contains($endpoint, 'brvtal_admin_require();')
    && str_contains($endpoint, 'brvtal_admin_require_csrf();'), 'auth and CSRF middleware');
admin_mail_check(str_contains($endpoint, 'CONTENT_LENGTH') && str_contains($endpoint, '12288'),
    'body-size and JSON gate');
admin_mail_check(!str_contains($endpoint, 'resend_send')
    && !str_contains($endpoint, 'mail('), 'no transport endpoint');
foreach (['buyer','door','staff',''] as $role) {
    admin_mail_reject(static fn()=>brvtalMailAdminRequire($role,false), 'non-mail role forbidden',DomainException::class);
}
admin_mail_check(brvtalTicketingCan('admin','mail') === true, 'mail capable reader');
admin_mail_reject(static fn()=>brvtalMailAdminRequire('admin',true),
    'ordinary admin cannot publish',DomainException::class);
brvtalMailAdminRequire('owner',true);
brvtalMailAdminRequire('superadmin',true);

$catalog = brvtal_mail_template_catalog();
$copy = $catalog['order_pending']['defaults'];
$copy['message'] = 'Hola {{event_name}}';
$validGet = brvtalMailAdminParseGet(['view'=>'active','key'=>'order_pending','scope'=>'ticketing']);
admin_mail_check($validGet['view']==='active','valid active read');
admin_mail_reject(static fn()=>brvtalMailAdminParseGet(['view'=>'catalog','admin_id'=>9]),
    'unknown GET field');
admin_mail_reject(static fn()=>brvtalMailAdminParseGet(['view'=>'history','key'=>'unknown','scope'=>'ticketing']),
    'non-catalog key');

$draft = brvtalMailAdminParsePost(['action'=>'save_draft','key'=>'order_pending',
    'scope'=>'ticketing','document'=>$copy,'revision'=>0]);
$preview = brvtalMailAdminParsePost(['action'=>'preview','key'=>'order_pending',
    'scope'=>'ticketing','document'=>$copy]);
$synthetic = brvtalMailAdminPreview(brvtal_mail_template_validate('order_pending','ticketing',$preview['document']));
admin_mail_check($synthetic['document']['message']==='Hola Evento de prueba'
    && $synthetic['mail_sent']===false && $synthetic['synthetic']===true,
    'synthetic preview without sender or token');
admin_mail_reject(static fn()=>brvtalMailAdminParsePost($draft+['recipient'=>'x@test.invalid']),
    'reject recipient injection');
admin_mail_reject(static fn()=>brvtalMailAdminParsePost(array_replace($draft,['revision'=>'0'])),
    'reject non-integer CAS');
admin_mail_reject(static fn()=>brvtalMailAdminParsePost(['action'=>'publish','key'=>'order_pending',
    'scope'=>'ticketing','version_id'=>true,'revision'=>1]),'reject boolean version id');
$unsafe = $copy; $unsafe['message'] = 'Token {{token}}';
admin_mail_reject(static fn()=>brvtal_mail_template_validate('order_pending','ticketing',$unsafe),
    'preview rejects sensitive variables');
$unsafe = $copy; $unsafe['message'] = '<script>alert(1)</script>';
admin_mail_reject(static fn()=>brvtal_mail_template_validate('order_pending','ticketing',$unsafe),
    'preview rejects executable HTML');

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL mail admin contract passed; MariaDB integration skipped.\n";
    return;
}
// All CRUD tests use an isolated disposable database; never real user data.
$prefix=(string)getenv('BRVTAL_TEST_DB_NAME');
admin_mail_check((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',$prefix),
    'synthetic DB namespace');
$host=(string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port=(int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user=(string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass=(string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$name=$prefix.'_mail_admin_'.bin2hex(random_bytes(4));
$opt=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false];
$server=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$pass,$opt);
$server->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
register_shutdown_function(static function () use ($server,$name): void {
    try { $server->exec('DROP DATABASE IF EXISTS '.$name); } catch (Throwable) {}
});
$pdo=new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",$user,$pass,$opt);
$schema=(string)file_get_contents(__DIR__.'/../../database/migration_mail_templates_01.sql');
foreach (preg_split('/;\s*(?=CREATE|$)/i',$schema) ?: [] as $sql) {
    if (trim($sql) !== '') $pdo->exec($sql);
}
$events=[];
$audit=static function (string $action, array $meta) use (&$events): void {
    $events[]=['action'=>$action,'meta'=>$meta];
};
$active=brvtalMailAdminDispatch($pdo,'admin',7,'GET',$validGet,$audit);
admin_mail_check($active['source']==='catalog' && $active['revision']===0,
    'active falls back to PHP default');
admin_mail_reject(static fn()=>brvtalMailAdminDispatch($pdo,'admin',7,'POST',$draft,$audit),
    'read-only admin cannot mutate',DomainException::class);
$catalogResponse=brvtalMailAdminDispatch($pdo,'admin',7,'GET',['view'=>'catalog'],$audit);
admin_mail_check(count($catalogResponse['items'])===10,'catalog allowlist');
$previewResponse=brvtalMailAdminDispatch($pdo,'admin',7,'POST',$preview,$audit);
admin_mail_check($previewResponse['mail_sent']===false && count($events)===0,
    'preview never sends, logs or saves');
$saved=brvtalMailAdminDispatch($pdo,'owner',7,'POST',$draft,$audit);
admin_mail_check($saved['revision']===1 && $saved['state']==='draft','save draft');
admin_mail_check(brvtalMailAdminDispatch($pdo,'admin',7,'GET',$validGet,$audit)['source']==='catalog',
    'draft never changes active');
admin_mail_reject(static fn()=>brvtalMailAdminDispatch($pdo,'owner',7,'POST',$draft,$audit),
    'stale CAS on save',DomainException::class);
$readDraft=brvtalMailAdminDispatch($pdo,'admin',7,'GET',[
    'view'=>'draft','key'=>'order_pending','scope'=>'ticketing','version_id'=>$saved['version_id']],$audit);
admin_mail_check($readDraft['document']['message']==='Hola {{event_name}}','read draft');
$publish=brvtalMailAdminParsePost(['action'=>'publish','key'=>'order_pending','scope'=>'ticketing',
    'version_id'=>$saved['version_id'],'revision'=>1]);
$published=brvtalMailAdminDispatch($pdo,'superadmin',7,'POST',$publish,$audit);
admin_mail_check($published['revision']===2 && $published['state']==='published','publish');
admin_mail_check(brvtalMailAdminDispatch($pdo,'admin',7,'GET',$validGet,$audit)['source']==='published',
    'publish is active');
admin_mail_reject(static fn()=>brvtalMailAdminDispatch($pdo,'owner',7,'POST',$publish,$audit),
    'stale CAS on publish',DomainException::class);
$changed=$copy;$changed['message']='Otra edición de prueba';
$newDraft=brvtalMailAdminDispatch($pdo,'owner',7,'POST',brvtalMailAdminParsePost([
    'action'=>'save_draft','key'=>'order_pending','scope'=>'ticketing',
    'document'=>$changed,'revision'=>2]),$audit);
brvtalMailAdminDispatch($pdo,'owner',7,'POST',brvtalMailAdminParsePost([
    'action'=>'publish','key'=>'order_pending','scope'=>'ticketing',
    'version_id'=>$newDraft['version_id'],'revision'=>3]),$audit);
$restored=brvtalMailAdminDispatch($pdo,'owner',7,'POST',brvtalMailAdminParsePost([
    'action'=>'restore','key'=>'order_pending','scope'=>'ticketing',
    'previous_version_id'=>$saved['version_id'],'revision'=>4]),$audit);
admin_mail_check($restored['revision']===5 && $restored['version_id']!==$saved['version_id'],
    'rollback creates new immutable published snapshot');
$after=brvtalMailAdminDispatch($pdo,'admin',7,'GET',$validGet,$audit);
admin_mail_check($after['document']['message']==='Hola {{event_name}}' && $after['revision']===5,
    'rollback correctly restored active version');
$history=brvtalMailAdminDispatch($pdo,'admin',7,'GET',[
    'view'=>'history','key'=>'order_pending','scope'=>'ticketing'],$audit);
admin_mail_check(count($history['items'])===3 && !isset($history['items'][0]['document_json']),
    'history metadata only');
admin_mail_check(count($events)===5, 'one audit per successful mutation');
foreach ($events as $entry) {
    admin_mail_check(array_keys($entry['meta'])===['key','scope','version_id','revision']
        && in_array($entry['action'],['save_draft','publish','restore'],true)
        && !str_contains(json_encode($entry,JSON_THROW_ON_ERROR),'Hola'),
        'audit has only action key scope and revision, no mail body');
}
admin_mail_check((int)$pdo->query('SELECT COUNT(*) FROM mail_template_versions')->fetchColumn()===3,
    'no duplicate mail or unintended writes');
echo "BRVTAL mail admin synthetic MariaDB integration passed.\n";
