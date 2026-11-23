<?php
declare(strict_types=1);
/** Issue #1890: exclusively synthetic staff mail renderer/delivery contracts. */
require_once __DIR__.'/../../config/admin_mailer.php';
require_once __DIR__.'/../../config/mail_template_store.php';
require_once __DIR__.'/../../config/mail_template_admin_api.php';

function staff_mail_assert(bool $ok, string $why): void
{
    if (!$ok) throw new RuntimeException('STAFF_MAIL_TEST_FAILED: '.$why);
}
$oldTransport=$GLOBALS['brvtal_password_mail_transport']??null;
$oldMode=getenv('BRVTAL_MAIL_TESTING');
putenv('BRVTAL_MAIL_TESTING=1');
$sent=[];
$GLOBALS['brvtal_password_mail_transport']=static function (
    string $email,string $subject,string $text,string $purpose,
    ?string $html,?string $key,?int $version
) use (&$sent): bool {
    $sent[]=compact('email','subject','text','purpose','html','key','version');
    return true;
};
try {
    $catalog=brvtal_mail_template_catalog();
    staff_mail_assert(isset($catalog['staff_password_recovery'],$catalog['staff_password_changed'])
        && $catalog['staff_password_recovery']['scope']==='staff',
        'both staff event keys belong to editable catalog');
    foreach (['staff_password_recovery','staff_password_changed'] as $key) {
        $bad=$catalog[$key]['defaults'];
        $bad['message']='Your bearer is {{token}}';
        $rejected=false;
        try { brvtal_mail_template_validate($key,'staff',$bad); }
        catch (InvalidArgumentException) { $rejected=true; }
        staff_mail_assert($rejected,'editor must reject sensitive token variables');
    }
    // Existing 3-argument mocks and untemplated transport remain compatible.
    $savedTransport=$GLOBALS['brvtal_password_mail_transport'];
    $GLOBALS['brvtal_password_mail_transport']=static fn($e,$s,$b): bool =>
        $e==='fake@brvtal.invalid' && $s==='Legacy' && $b==='Body';
    staff_mail_assert(brvtalAdminMailSend('fake@brvtal.invalid','Legacy','Body','notice'),
        'legacy injectable transport unchanged');
    $GLOBALS['brvtal_password_mail_transport']=$savedTransport;
    $security=(string)file_get_contents(__DIR__.'/../../config/admin_password_security.php');
    staff_mail_assert(str_contains($security,'brvtal_password_reset_issue($pdo,')
        && str_contains($security,'brvtal_password_reset_revoke_token($pdo,')
        && str_contains($security,'BRVTAL_PASSWORD_RESET_TTL_SECONDS = 3600')
        && str_contains($security,"'password_recovery',")
        && str_contains($security, '$pdo,')
        && str_contains($security, '$link'),
        'reset issues, one-use revocation and TTL preserved');
    if (getenv('BRVTAL_INTEGRATION_TESTS')!=='1') {
        echo "BRVTAL staff template transport contracts OK (MariaDB skipped).\n";
    } else {
        $prefix=(string)getenv('BRVTAL_TEST_DB_NAME');
        staff_mail_assert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',$prefix),
            'isolated synthetic DB only');
        $host=(string)(getenv('BRVTAL_TEST_DB_HOST')?:'127.0.0.1');
        $port=(int)(getenv('BRVTAL_TEST_DB_PORT')?:3306);
        $user=(string)(getenv('BRVTAL_TEST_DB_USER')?:'root');
        $pass=(string)(getenv('BRVTAL_TEST_DB_PASS')?:'');
        $name=$prefix.'_staff_mail_'.bin2hex(random_bytes(4));
        $attrs=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false];
        $server=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$pass,$attrs);
        $server->exec("CREATE DATABASE ".$name." CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        register_shutdown_function(static function () use ($server,$name): void {
            try {$server->exec("DROP DATABASE IF EXISTS ".$name);} catch (Throwable) {}
        });
        $pdo=new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $user,$pass,$attrs);
        $pdo->exec((string)file_get_contents(__DIR__.'/../../database/migration_mail_templates_01.sql'));
        $pdo->exec("CREATE TABLE media (
            id INT NOT NULL PRIMARY KEY,
            file_path VARCHAR(255) NOT NULL,
            status VARCHAR(32) NOT NULL,
            type VARCHAR(32) NOT NULL
        ) ENGINE=InnoDB");
        $pdo->exec("INSERT INTO media(id,file_path,status,type)
            VALUES(71,'/uploads/staff-ci-logo.png','published','image')");
        $token=str_repeat('a',64);
        $resetLink='https://www.brvtal.com.co/discadmin/reset-password.php#token='.$token;
        $index=count($sent);
        staff_mail_assert(brvtalAdminMailSend('fake@brvtal.invalid','Legacy subject',
            'Legacy text','password_recovery',$pdo,$resetLink),
            'no published template preserves plaintext transport');
        staff_mail_assert($sent[$index]['html']===null
            && $sent[$index]['version']===null
            && $sent[$index]['subject']==='Legacy subject',
            'no customization: original subject and text');

        $key='staff_password_recovery';
        $doc=$catalog[$key]['defaults'];
        $doc['subject']='Recuperación editable CI';
        $doc['message']='Texto CI de recuperación publicado.';
        $doc['theme']['accent']='#40bbab';
        $doc['theme']['logo_media_id']=71;
        $allow=static fn(int $id): bool => $id===71;
        $draft=brvtal_mail_template_save_draft($pdo,$key,'staff',$doc,1,0,$allow);
        brvtal_mail_template_publish($pdo,$key,'staff',$draft['version_id'],1,1,$allow);

        $i=count($sent);
        staff_mail_assert(brvtalAdminMailSend('fake@brvtal.invalid','Old','Old',
            'password_recovery',$pdo,$resetLink),'mock SMTP recovery delivery');
        $receipt=$sent[$i];
        staff_mail_assert($receipt['subject']===$doc['subject']
            && $receipt['key']===$key
            && $receipt['version']===$draft['version_id']
            && str_contains((string)$receipt['html'],'<!doctype html>')
            && str_contains((string)$receipt['html'],'#40bbab')
            && str_contains((string)$receipt['html'],'/uploads/staff-ci-logo.png')
            && str_contains((string)$receipt['html'],'Texto CI de recuperación')
            && str_contains((string)$receipt['html'],'href="'.$resetLink.'"')
            && str_contains($receipt['text'],$resetLink),
            'HTML, plain AltBody, published text/logo/accent and version receipt');

        $next=$doc;
        $next['message']='Versión posterior publicada sin tocar código.';
        $draft2=brvtal_mail_template_save_draft($pdo,$key,'staff',$next,1,2,$allow);
        brvtal_mail_template_publish($pdo,$key,'staff',$draft2['version_id'],1,3,$allow);
        $i=count($sent);
        brvtalAdminMailSend('fake@brvtal.invalid','Old','Old',
            'password_recovery',$pdo,$resetLink);
        staff_mail_assert($sent[$i]['version']===$draft2['version_id']
            && str_contains((string)$sent[$i]['html'],'Versión posterior'),
            'published revision applies at send-time');

        $changedKey='staff_password_changed';
        $changed=$catalog[$changedKey]['defaults'];
        $changed['subject']='Cambio de clave editable CI';
        $changed['theme']['accent']='#33bb99';
        $changedDraft=brvtal_mail_template_save_draft($pdo,$changedKey,'staff',$changed,1,0);
        brvtal_mail_template_publish($pdo,$changedKey,'staff',$changedDraft['version_id'],1,1);
        $i=count($sent);
        staff_mail_assert(brvtalAdminMailSend('fake@brvtal.invalid','Old','Old',
            'password_changed_authenticated',$pdo,'https://www.brvtal.com.co/discadmin'),
            'published staff change notification');
        staff_mail_assert($sent[$i]['key']===$changedKey
            && $sent[$i]['version']===$changedDraft['version_id']
            && str_contains((string)$sent[$i]['html'],'#33bb99')
            && str_contains($sent[$i]['text'],'https://www.brvtal.com.co/discadmin'),
            'changed notification HTML and plain text');

        $before=count($sent);
        staff_mail_assert(!brvtalAdminMailSend('fake@brvtal.invalid','Old','Old',
            'password_recovery',$pdo,
            'https://evil.invalid/discadmin/reset-password.php#token='.$token)
            && count($sent)===$before,'foreign reset URL rejected before SMTP');
        staff_mail_assert(!brvtalAdminMailSend('fake@brvtal.invalid','Old','Old',
            'password_recovery',$pdo,
            'https://www.brvtal.com.co/discadmin/reset-password.php#token=short')
            && count($sent)===$before,'invalid token rejected before SMTP');
        $pdo->prepare('UPDATE mail_template_versions SET document_json=? WHERE id=?')
            ->execute(['{"title":"<script>bad</script>"}',$draft2['version_id']]);
        staff_mail_assert(!brvtalAdminMailSend('fake@brvtal.invalid','Old','Old',
            'password_recovery',$pdo,$resetLink) && count($sent)===$before,
            'corrupt published version fails closed');
        $pdo->prepare('UPDATE mail_template_versions SET document_json=? WHERE id=?')
            ->execute([json_encode($next,JSON_THROW_ON_ERROR),$draft2['version_id']]);
        $preview=brvtalMailAdminPreview($next);
        staff_mail_assert($preview['mail_sent']===false && $preview['synthetic']===true
            && !str_contains(json_encode($preview,JSON_THROW_ON_ERROR),$token),
            'editor preview never receives token');
        $stored=(string)$pdo->query('SELECT GROUP_CONCAT(document_json SEPARATOR " ") FROM mail_template_versions')
            ->fetchColumn();
        staff_mail_assert(!str_contains($stored,$token),
            'version history never persists the one-use link');
        echo "BRVTAL staff mail template published render and DB contracts passed.\n";
    }
} finally {
    if ($oldTransport===null) unset($GLOBALS['brvtal_password_mail_transport']);
    else $GLOBALS['brvtal_password_mail_transport']=$oldTransport;
    putenv($oldMode===false ? 'BRVTAL_MAIL_TESTING' : 'BRVTAL_MAIL_TESTING='.$oldMode);
}
