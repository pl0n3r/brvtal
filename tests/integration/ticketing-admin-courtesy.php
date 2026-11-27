<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/ticketing_admin_courtesy.php';
function courtesyReadAssert(bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException('Courtesy read: '.$why);
}
foreach ([['event_id'=>'0'],['ticket_type_id'=>'1 OR 1=1'],['invite_id'=>'-1'],
    ['cursor'=>'01'],['limit'=>'51'],['category'=>'unknown'],['status'=>'paid'],
    ['recipient_digest'=>'x'],['q'=>'guest@example.test'],['event_id'=>['1']],
    ['invite_id'=>'99999999999999999999999999']] as $bad) {
    try { brvtalCourtesyAdminFilters($bad); throw new RuntimeException('invalid accepted'); }
    catch (InvalidArgumentException) {}
}
if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "Courtesy read filters OK; database fixture skipped.\n"; exit(0);
}
$prefix=(string)getenv('BRVTAL_TEST_DB_NAME');
courtesyReadAssert(preg_match('/^brvtal_test[A-Za-z0-9_]*$/D',$prefix)===1,'disposable namespace');
$name=$prefix.'_courtesy_read_'.bin2hex(random_bytes(4));
$dsn='mysql:host='.(getenv('BRVTAL_TEST_DB_HOST')?:'127.0.0.1')
    .';port='.(getenv('BRVTAL_TEST_DB_PORT')?:3306).';charset=utf8mb4';
$user=(string)(getenv('BRVTAL_TEST_DB_USER')?:'root');
$pass=(string)getenv('BRVTAL_TEST_DB_PASS');
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false];
$root=new PDO($dsn,$user,$pass,$opts);
$root->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
try {
    $db=new PDO($dsn.';dbname='.$name,$user,$pass,$opts);
    foreach ([
        'CREATE TABLE events(id INT UNSIGNED PRIMARY KEY,title VARCHAR(180)) ENGINE=InnoDB',
        'CREATE TABLE admins(id INT UNSIGNED PRIMARY KEY,staff_role VARCHAR(32),is_active TINYINT) ENGINE=InnoDB',
        'CREATE TABLE event_ticket_types(id INT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED,name VARCHAR(100),
            UNIQUE KEY type_event(id,event_id)) ENGINE=InnoDB',
        'CREATE TABLE ticket_orders(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB',
        'CREATE TABLE ticket_order_items(id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED,
            event_id INT UNSIGNED,ticket_type_id INT UNSIGNED) ENGINE=InnoDB',
        'CREATE TABLE ticket_inventory_types(ticket_type_id INT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED,
            courtesy_capacity INT UNSIGNED) ENGINE=InnoDB',
        "INSERT INTO events VALUES (1,'Evento uno'),(2,'Evento dos'),(3,'Evento tres')",
        "INSERT INTO admins VALUES (7,'superadmin',1),(8,'admin',1),(9,'door',1),(10,'superadmin',0),(11,'owner',1)",
        "INSERT INTO event_ticket_types VALUES (11,1,'General'),(12,1,'VIP'),(21,2,'General'),(31,3,'Invitados')",
        'INSERT INTO ticket_inventory_types VALUES (11,1,5),(12,1,5),(21,2,5),(31,3,5)'
    ] as $sql) $db->exec($sql);
    // Real invitation, entry and mail migrations, only synthetic prerequisites.
    foreach (['migration_ticketing_entries_01.sql','migration_ticketing_courtesy_01.sql',
        'migration_ticketing_mail_outbox_01.sql','migration_ticketing_mail_history_01.sql'] as $file) {
        $sql=(string)file_get_contents(__DIR__.'/../../database/'.$file);
        $sql=(string)preg_replace('/^\s*--[^\r\n]*$/m','',$sql);
        foreach (array_filter(array_map(trim(...),explode(';',$sql))) as $statement) $db->exec($statement);
    }
    // id,event,type,category,invite state,actual entry state,revision
    $cases=[
        101=>[1,11,'artist','issued','issued',0],
        102=>[1,11,'staff','issued','used',0],
        103=>[1,12,'press','revoked','revoked',0],
        104=>[2,21,'partner','issued','issued',1],
        105=>[2,21,'staff','used','used',0],
        106=>[1,12,'artist','issued','refunded',0],
        107=>[2,21,'artist','issued','issued',0],
        108=>[1,11,'staff','issued','issued',0],
    ];
    $addOrder=$db->prepare('INSERT INTO ticket_orders VALUES (?)');
    $addItem=$db->prepare('INSERT INTO ticket_order_items VALUES (?,?,?,?)');
    $addEntry=$db->prepare("INSERT INTO ticket_entries(id,public_id,order_item_id,event_id,ticket_type_id,
        unit_number,status,issued_at_utc) VALUES (?,?,?,?,?,1,?,'2026-11-20')");
    $addInvite=$db->prepare("INSERT INTO ticket_courtesy_invites(id,event_id,ticket_type_id,order_id,
        entry_id,category,recipient_digest,request_digest,reason,authorized_by,status,resend_requests,
        revoked_by,revoked_at_utc) VALUES (?,?,?,?,?,?,?,?,?,7,?,?,?,?)");
    foreach ($cases as $id=>[$event,$type,$cat,$invite,$entry,$revision]) {
        // Broken cross-event linkage must be exposed as unknown, not as a valid used/issued ticket.
        $actualEvent=$id===108?2:$event; $actualType=$id===108?21:$type;
        $addOrder->execute([$id]);
        $addItem->execute([$id,$id,$actualEvent,$actualType]);
        $addEntry->execute([$id,str_pad((string)$id,16,'0',STR_PAD_LEFT),$id,$actualEvent,$actualType,$entry]);
        $addInvite->execute([$id,$event,$type,$id,$id,$cat,hash('sha256','recipient'.$id),
            hash('sha256','request'.$id),'Private fixture reason',$invite,$revision,
            $invite==='revoked'?7:null,$invite==='revoked'?'2026-11-21':null]);
    }
    $mail=$db->prepare("INSERT INTO ticket_mail_outbox(id,message_key,payload_hash,related_ref,recipient_email,
        subject,body_text,state,provider_message_id,accepted_at) VALUES (?,?,?,?,'guest@example.test',
        'Private fixture subject','Private fixture bearer BRVTAL:T1:',?,?,?)");
    $history=$db->prepare("INSERT INTO ticket_mail_history(outbox_id,message_key,recipient_hint,recipient_digest,
        resend_email_id,delivery_state,state_at,retention_until) VALUES (?,?, 'g***@example.test',?,?,?,
        '2026-11-22','2027-01-01')");
    foreach ([
        [101,'queued',null,null,'courtesy:101',null],
        [102,'accepted','provider102','2026-11-21','courtesy:102',null],
        [103,'accepted','provider103','2026-11-21','courtesy:103','provider103'],
        [104,'accepted','provider104','2026-11-21','courtesy:104','provider104'],
        [105,'queued',null,null,'courtesy:999',null],
        [106,'accepted',null,null,'courtesy:106',null],
        [107,'accepted','provider107','2026-11-21','courtesy:107','foreign107']
    ] as [$id,$state,$provider,$accepted,$ref,$historyProvider]) {
        $key='courtesy:'.$id.':0';
        $mail->execute([$id,$key,hash('sha256','payload'.$id),$ref,$state,$provider,$accepted]);
        if ($historyProvider !== null) $history->execute([$id,$key,hash('sha256','history'.$id),$historyProvider,'delivered']);
    }
    $snapshot=static function () use ($db): string {
        $rows=[];
        foreach (['ticket_courtesy_invites','ticket_entries','ticket_order_items','ticket_orders',
            'ticket_mail_outbox','ticket_mail_history','admins'] as $table) {
            $rows[$table]=$db->query("SELECT * FROM $table ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        }
        return hash('sha256',serialize($rows));
    };
    $before=$snapshot();
    foreach ([0,8,9,10,99] as $actor) {
        try { brvtalCourtesyAdminRead($db,$actor,[]); throw new RuntimeException('unauthorized read'); }
        catch (DomainException $e) { courtesyReadAssert($e->getMessage()==='FORBIDDEN','RBAC'); }
    }
    $all=brvtalCourtesyAdminRead($db,7,[]);
    courtesyReadAssert($all===brvtalCourtesyAdminRead($db,11,[]),'owner alias');
    courtesyReadAssert(count($all['rows'])===8 && !$all['has_more'] && $all['next_cursor']===null,'all rows');
    $byId=array_column($all['rows'],null,'id');
    courtesyReadAssert($all['totals']===['issued'=>3,'used'=>2,'revoked'=>1,'refunded'=>1,'unknown'=>1],'effective totals');
    courtesyReadAssert($byId[102]['status']==='used' && $byId[102]['invite_status']==='issued'
        && $byId[102]['incident'] && $byId[108]['status']==='unknown' && $byId[108]['incident']
        && !$byId[103]['incident'],'discrepancy and relation');
    courtesyReadAssert(array_column($all['rows'],'mail_state','id')===[
        108=>'unknown',107=>'accepted',106=>'unknown',105=>'unknown',104=>'unknown',
        103=>'delivered',102=>'accepted',101=>'queued'],'only current authenticated mail proof');
    $ids=[]; $cursor=null;
    do {
        $filters=['limit'=>'2']; if ($cursor!==null) $filters['cursor']=(string)$cursor;
        $page=brvtalCourtesyAdminRead($db,7,$filters);
        courtesyReadAssert($page['totals']===$all['totals'],'cursor independent totals');
        array_push($ids,...array_column($page['rows'],'id')); $cursor=$page['next_cursor'];
    } while ($page['has_more']);
    courtesyReadAssert($ids===[108,107,106,105,104,103,102,101],'stable descending keyset');
    $subset=brvtalCourtesyAdminRead($db,7,['event_id'=>'1','ticket_type_id'=>'11','category'=>'staff','status'=>'used']);
    courtesyReadAssert(array_column($subset['rows'],'id')===[102] && $subset['totals']['used']===1,'combined filters');
    courtesyReadAssert(array_column(brvtalCourtesyAdminRead($db,7,['invite_id'=>'103'])['rows'],'id')===[103]
        && brvtalCourtesyAdminRead($db,7,['invite_id'=>'999'])['rows']===[],'internal reference');
    courtesyReadAssert(array_column($all['catalog'],'ticket_type_id')===[31,21,11,12],'independent real catalog');
    $allowed=['id','event_id','event_title','ticket_type_id','ticket_type_name','category','created_at_utc',
        'invite_status','status','resend_requests','mail_state','incident'];
    foreach ($all['rows'] as $row) courtesyReadAssert(array_keys($row)===$allowed,'exact projection');
    $json=json_encode($all,JSON_THROW_ON_ERROR);
    foreach (['guest@example.test','recipient_digest','request_digest','BRVTAL:T1:','Private fixture',
        'provider103','public_id','recipient_hint'] as $secret)
        courtesyReadAssert(!str_contains($json,$secret),'no secrets or PII');
    courtesyReadAssert($before===$snapshot(),'read-only database snapshot');
    $db->exec('DROP TABLE ticket_mail_history');
    courtesyReadAssert(brvtalCourtesyAdminRead($db,7,['invite_id'=>'103'])['rows'][0]['mail_state']==='accepted',
        'missing history does not invent delivery');
    $db->exec('DROP TABLE ticket_mail_outbox');
    courtesyReadAssert(brvtalCourtesyAdminRead($db,7,['invite_id'=>'103'])['rows'][0]['mail_state']==='unknown',
        'missing outbox is unknown');
} finally {
    $root->exec('DROP DATABASE IF EXISTS '.$name);
}
echo "BRVTAL courtesy admin read MariaDB PASS.\n";
