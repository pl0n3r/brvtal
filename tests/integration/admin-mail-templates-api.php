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
    'scope'=>'ticketing','template_copy'=>$copy,'revision'=>0]);
$preview = brvtalMailAdminParsePost(['action'=>'preview','key'=>'order_pending',
    'scope'=>'ticketing','template_copy'=>$copy]);
$synthetic = brvtalMailAdminPreview(brvtal_mail_template_validate('order_pending','ticketing',$preview['template_copy']));
admin_mail_check($synthetic['template_copy']['message']==='Hola Evento de prueba'
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
admin_mail_check(count($catalogResponse['items'])===14,'catalog includes independent recovery template');
$recoveryItem = array_values(array_filter($catalogResponse['items'],
    static fn(array $item): bool => $item['key'] === 'ticket_recovery'));
admin_mail_check(count($recoveryItem)===1 && $recoveryItem[0]['scope']==='ticketing',
    'editor discovers recovery through catalog API');
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
admin_mail_check(!array_key_exists('author_admin_id', $readDraft),
    'read API does not expose staff identifiers');
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
    'template_copy'=>$changed,'revision'=>2]),$audit);
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
foreach ($history['items'] as $historyVersion) {
    admin_mail_check(!array_key_exists('author_admin_id', $historyVersion)
        && !array_key_exists('discarded_by_admin_id', $historyVersion),
        'history response does not expose staff identifiers');
}
admin_mail_check(count($events)===5, 'one audit per successful mutation');
foreach ($events as $entry) {
    admin_mail_check(array_keys($entry['meta'])===['key','scope','version_id','revision']
        && in_array($entry['action'],['save_draft','publish','restore'],true)
        && !str_contains(json_encode($entry,JSON_THROW_ON_ERROR),'Hola'),
        'audit has only action key scope and revision, no mail body');
}
admin_mail_check((int)$pdo->query('SELECT COUNT(*) FROM mail_template_versions')->fetchColumn()===3,
    'no duplicate mail or unintended writes');
// Run the full recovery acceptance in this dedicated MariaDB workflow.
require_once __DIR__.'/../../config/ticketing_mail_templates.php';
// Recovery is versioned independently, and does not trigger or transport mail.
$recoveryData=['event'=>'Noche de prueba','link'=>'https://www.brvtal.com.co/buyer/','order_number'=>9];
$recoveryDefault=brvtal_ticket_mail_render('ticket_recovery',$recoveryData,$pdo);
admin_mail_check($recoveryDefault['template_key']==='ticket_recovery'
    && $recoveryDefault['template_source']==='catalog'
    && $recoveryDefault['template_version']===null
    && str_contains($recoveryDefault['text'],'Inicia sesión en Mi cuenta')
    && str_contains($recoveryDefault['html'],'href="https://www.brvtal.com.co/buyer/"')
    && !str_contains($recoveryDefault['text'],'código QR'), 'account-only default');
foreach (['https://www.brvtal.com.co/buyer/?','https://www.brvtal.com.co/buyer/#',
    'https://www.brvtal.com.co/buyer/?token=x','https://www.brvtal.com.co/buyer/#secret',
    'https://www.brvtal.com.co/buyer/../','https://www.brvtal.com.co/buyer/%2e%2e/',
    'https://www.brvtal.com.co/buyer/orders','https://evil.invalid/buyer/',
    'http://www.brvtal.com.co/buyer/','https://user:pass@www.brvtal.com.co/buyer/',
    'https://www.brvtal.com.co:443/buyer/','https://brvtal.com.co/buyer/'] as $unsafeLink) {
    try {
        brvtal_ticket_mail_render('ticket_recovery',array_replace($recoveryData,['link'=>$unsafeLink]),$pdo);
        admin_mail_check(false,'recovery URL must be rejected');
    } catch (InvalidArgumentException $error) {
        admin_mail_check(in_array($error->getMessage(),['MAIL_LINK_INVALID','MAIL_LINK_NOT_PROTECTED'],true),
            'opaque recovery URL rejection');
    }
}
foreach (['tickets'=>null,'guest_password_link'=>null,'token'=>'synthetic',
    'activation_link'=>'https://brvtal.invalid/activation','qr'=>null] as $field=>$value) {
    try {
        brvtal_ticket_mail_render('ticket_recovery',$recoveryData+[$field=>$value],$pdo);
        admin_mail_check(false,'recovery cannot accept credential fields');
    } catch (InvalidArgumentException $error) {
        admin_mail_check($error->getMessage()==='MAIL_RECOVERY_FIELDS_INVALID','recovery fields rejected');
    }
}
try {
    brvtal_ticket_mail_render('ticket_recovery',$recoveryData);
    admin_mail_check(false,'legacy recovery fallback must be rejected');
} catch (InvalidArgumentException $error) {
    admin_mail_check($error->getMessage()==='MAIL_TEMPLATE_UNKNOWN','legacy keys unchanged');
}
$recoveryCopy=brvtal_mail_template_catalog()['ticket_recovery']['defaults'];
$recoveryCopy['message']='Consulta tus boletas vigentes para {{event_name}} desde Mi cuenta.';
$recoveryDraft=brvtal_mail_template_save_draft($pdo,'ticket_recovery','ticketing',$recoveryCopy,1,0);
admin_mail_check(brvtal_ticket_mail_render('ticket_recovery',$recoveryData,$pdo)===$recoveryDefault,
    'recovery draft cannot affect catalog delivery');
$recoveryPub=brvtal_mail_template_publish($pdo,'ticket_recovery','ticketing',$recoveryDraft['version_id'],1,1);
$recoverySnapshot=brvtal_ticket_mail_render('ticket_recovery',$recoveryData,$pdo);
admin_mail_check($recoverySnapshot['template_source']==='published'
    && $recoverySnapshot['template_version']===$recoveryPub['version_id']
    && str_contains($recoverySnapshot['text'],'Consulta tus boletas vigentes para Noche de prueba'),
    'published recovery revision rendered with real version');
$storedRecovery=$pdo->prepare('SELECT document_json FROM mail_template_versions WHERE id=?');
$storedRecovery->execute([$recoverySnapshot['template_version']]);
$originalRecoveryDocument=$storedRecovery->fetchColumn();
$recoveryCopy['message']='Nuevo texto de recuperación autenticada.';
$nextRecovery=brvtal_mail_template_save_draft($pdo,'ticket_recovery','ticketing',$recoveryCopy,1,2);
admin_mail_check(brvtal_ticket_mail_render('ticket_recovery',$recoveryData,$pdo)===$recoverySnapshot,
    'published recovery snapshot unaffected by later draft');
brvtal_mail_template_publish($pdo,'ticket_recovery','ticketing',$nextRecovery['version_id'],1,3);
$updatedRecovery=brvtal_ticket_mail_render('ticket_recovery',$recoveryData,$pdo);
$storedRecovery->execute([$recoverySnapshot['template_version']]);
admin_mail_check(is_string($originalRecoveryDocument)
    && $storedRecovery->fetchColumn()===$originalRecoveryDocument,
    'old recovery version stored immutably');
admin_mail_check($updatedRecovery['template_version']===$nextRecovery['version_id']
    && $updatedRecovery['text']!==$recoverySnapshot['text']
    && str_contains($recoverySnapshot['text'],'Consulta tus boletas vigentes para Noche de prueba'),
    'new revision leaves prior rendered snapshot intact');
$purchase=brvtal_ticket_mail_render('payment_approved',$recoveryData,$pdo);
$reminder=brvtal_ticket_mail_render('reminder',$recoveryData,$pdo);
admin_mail_check($purchase['template_version']===null && $reminder['template_version']===null
    && $purchase['template_key']==='payment_approved' && $reminder['template_key']==='reminder',
    'recovery publication never changes purchase or reminder identity');
foreach (['event','link'] as $field) {
    foreach ([null, [], 123] as $invalid) {
        admin_mail_reject(static fn()=>brvtal_ticket_mail_render(
            'ticket_recovery',array_replace($recoveryData,[$field=>$invalid]),$pdo),
            'recovery rejects malformed input types');
    }
}
$oldRecoveryFixture=getenv('BRVTAL_TICKET_MAIL_TESTING');
try {
    putenv('BRVTAL_TICKET_MAIL_TESTING=1');
    $fixtureRecovery=brvtal_ticket_mail_render('ticket_recovery',
        array_replace($recoveryData,['link'=>'https://brvtal.invalid/buyer/']),$pdo);
    admin_mail_check(str_contains($fixtureRecovery['html'],'href="https://brvtal.invalid/buyer/"'),
        'synthetic recovery CTA permitted in explicit fixture mode');
} finally {
    putenv($oldRecoveryFixture===false ? 'BRVTAL_TICKET_MAIL_TESTING'
        : 'BRVTAL_TICKET_MAIL_TESTING='.$oldRecoveryFixture);
}
$ticketUrl='https://www.brvtal.com.co/mi-cuenta/boleta#BRVTAL:T1:'
    .str_repeat('a',32).':'.str_repeat('B',43);
$paid=brvtal_ticket_mail_render('payment_approved',$recoveryData+
    ['tickets'=>[['label'=>'Boleta sintética','url'=>$ticketUrl]]],$pdo);
admin_mail_check(str_contains($paid['text'],$ticketUrl)
    && !str_contains($recoverySnapshot['text'],$ticketUrl),'purchase-only QR preserved');
foreach (['verify_account'=>'verify','reset_password'=>'confirm-reset'] as $key=>$action) {
    $auth=brvtal_ticket_mail_render($key,['link'=>'https://www.brvtal.com.co/mi-cuenta/restablecer?accion='
        .$action.'&token='.str_repeat('Z',43)],$pdo);
    admin_mail_check($auth['template_key']===$key && $auth['template_version']===null
        && str_contains($auth['text'],'accion='.$action),'auth renderer unchanged');
}
echo "BRVTAL recovery renderer synthetic MariaDB contract passed.\n";
echo "BRVTAL mail admin synthetic MariaDB integration passed.\n";
