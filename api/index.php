<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/totp_auth.php';

function client_key(): string {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash('sha256', $ip . '|' . strtolower((string)($_POST['email'] ?? '')));
}

function rate_limit_login(string $email): void {
    $dir = __DIR__ . '/../storage/rate_limits';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $key = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . strtolower($email));
    $file = $dir . '/' . $key . '.json';
    $now = time(); $window = 900; $max = 5;
    $data = ['attempts'=>[], 'blocked_until'=>0];
    if (is_file($file)) {
        $decoded = json_decode((string)@file_get_contents($file), true);
        if (is_array($decoded)) $data = array_replace($data, $decoded);
    }
    $data['attempts'] = array_values(array_filter((array)$data['attempts'], static fn($t) => is_int($t) && $t > $now - $window));
    if ((int)$data['blocked_until'] > $now) {
        json_response(['ok'=>false,'error'=>'RATE_LIMITED','retry_after'=>(int)$data['blocked_until']-$now],429,['Retry-After'=>(string)((int)$data['blocked_until']-$now)]);
    }
    $data['attempts'][] = $now;
    if (count($data['attempts']) > $max) {
        $data['blocked_until'] = $now + 900;
        @file_put_contents($file, json_encode($data), LOCK_EX);
        json_response(['ok'=>false,'error'=>'RATE_LIMITED','retry_after'=>900],429,['Retry-After'=>'900']);
    }
    @file_put_contents($file, json_encode($data), LOCK_EX);
}

function method_not_allowed(): never { json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405,['Allow'=>'GET, POST, PUT, DELETE']); }
function ensure_string(array &$d, string $key, int $max): void { if (array_key_exists($key,$d)) $d[$key] = mb_substr(trim((string)$d[$key]),0,$max); }
function sanitize_payload(string $resource, array $d): array {
    $limits=['title'=>180,'slug'=>190,'name'=>180,'venue'=>180,'city'=>120,'skin'=>60,'accent'=>30,'cover_image'=>500,'ticket_url'=>700,'photo'=>500,'instagram_url'=>700,'soundcloud_url'=>700,'website_url'=>700,'external_url'=>700,'embed_url'=>700,'file_path'=>500,'mime_type'=>120,'alt_text'=>255,'seo_title'=>190,'seo_description'=>320,'locale'=>2,'platform'=>20,'status'=>20,'type'=>20,'role'=>80,'currency'=>3,'price'=>30,'payment_instructions'=>4000];
    foreach($limits as $k=>$n) ensure_string($d,$k,$n);
    foreach(['ticket_url','instagram_url','soundcloud_url','website_url','external_url','embed_url'] as $k) if(array_key_exists($k,$d)) { $v=valid_url_or_empty($d[$k]); if($d[$k]!=='' && $v===null) json_response(['ok'=>false,'error'=>'INVALID_URL','field'=>$k],422); $d[$k]=$v; }
    if(isset($d['slug'])) $d['slug']=slugify((string)$d['slug']);
    if(isset($d['accent']) && $d['accent']!=='' && !preg_match('/^#[0-9a-fA-F]{6}$/',(string)$d['accent'])) json_response(['ok'=>false,'error'=>'INVALID_ACCENT'],422);
    if(isset($d['status']) && !in_array($d['status'], $resource==='events'?['draft','published','upcoming','tickets_available','last_tickets','sold_out','cancelled','finished','archived']:($resource==='ticket_types'?['draft','active','inactive','sold_out']:['draft','published']), true)) json_response(['ok'=>false,'error'=>'INVALID_STATUS'],422);
    if(isset($d['locale']) && !in_array($d['locale'],['es','en'],true)) json_response(['ok'=>false,'error'=>'INVALID_LOCALE'],422);
    if(isset($d['platform']) && !in_array($d['platform'],['soundcloud','youtube','spotify','other'],true)) json_response(['ok'=>false,'error'=>'INVALID_PLATFORM'],422);
    if($resource==='ticket_types' && isset($d['event_id']) && (int)$d['event_id']<1) json_response(['ok'=>false,'error'=>'INVALID_EVENT_ID'],422);
    if($resource==='ticket_types' && isset($d['name']) && trim((string)$d['name'])==='') json_response(['ok'=>false,'error'=>'TICKET_NAME_REQUIRED'],422);
    if(isset($d['currency']) && $d['currency']!=='' && !preg_match('/^[A-Z]{3}$/',(string)$d['currency'])) json_response(['ok'=>false,'error'=>'INVALID_CURRENCY'],422);
    if(isset($d['price']) && $d['price']!=='' && (!is_numeric($d['price']) || (float)$d['price']<0)) json_response(['ok'=>false,'error'=>'INVALID_PRICE'],422);
    if(isset($d['content_json']) && strlen((string)$d['content_json']) > 2*1024*1024) json_response(['ok'=>false,'error'=>'CONTENT_TOO_LARGE'],422);
    return $d;
}
function allowed_fields(string $resource): array {
    return ['events'=>['title','slug','event_date','venue','city','description','skin','accent','cover_image','ticket_url','ticket_instructions','ticket_qr','featured','published_at','cancelled_at','finished_at','archive_year','status','sort_order'],'artists'=>['name','slug','bio','photo','instagram_url','soundcloud_url','website_url','collective_status','collective_order','collective_joined_at','collective_left_at','status','sort_order'],'sets'=>['title','slug','artist_id','event_id','platform','external_url','embed_url','cover_image','description','status','sort_order'],'media'=>['type','title','file_path','mime_type','file_size','alt_text','status'],'pages'=>['title','slug','locale','content_json','seo_title','seo_description','status'],'ticket_types'=>['event_id','name','description','price','currency','external_url','payment_instructions','qr_image','status','available_from','available_until','sort_order']][$resource] ?? [];
}
function table_for(string $resource): string { return ['events'=>'events','artists'=>'artists','sets'=>'sets_media','media'=>'media','pages'=>'pages','ticket_types'=>'event_ticket_types','settings'=>'settings'][$resource] ?? ''; }
function handle_exception(Throwable $e): never { brvtal_log('API_ERROR','Unhandled API exception',['class'=>get_class($e),'message'=>$e->getMessage(),'line'=>$e->getLine()]); json_response(['ok'=>false,'error'=>'INTERNAL_ERROR'],500); }

try {
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    $path=trim(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)??'/','/');
    $script=trim($_SERVER['SCRIPT_NAME']??'','/');
    if($script!=='' && str_starts_with($path,$script)) $path=trim(substr($path,strlen($script)),'/');
    $segments=$path===''?[]:explode('/',$path);
    $resource=$segments[0]??''; $id=isset($segments[1])&&ctype_digit($segments[1])?(int)$segments[1]:null; $action=$segments[1]??'';

    if ($resource==='health') {
        if($method!=='GET') method_not_allowed();
        $started=microtime(true);
        try { $pdo=db(); $pdo->query('SELECT 1'); json_response(['ok'=>true,'app'=>'BRVTAL','status'=>'healthy','database'=>'connected','driver'=>$pdo->getAttribute(PDO::ATTR_DRIVER_NAME),'server'=>$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),'php'=>PHP_VERSION,'time'=>date(DATE_ATOM),'latency_ms'=>round((microtime(true)-$started)*1000,2)]); }
        catch(Throwable $e){ brvtal_log('HEALTH_ERROR','Health check failed',['message'=>$e->getMessage()]); json_response(['ok'=>false,'status'=>'degraded','database'=>'error','php'=>PHP_VERSION,'time'=>date(DATE_ATOM)],503); }
    }

    if ($resource==='auth') {
        brvtal_admin_session_start();

        if ($method==='POST') {
            $peek=input_json();
            if (($peek['action'] ?? '') === 'totp_cancel') {
                brvtal_totp_pending_clear();
                json_response(['ok'=>true]);
            }
            $GLOBALS['brvtal_auth_input']=$peek;
        }

        // Logout is evaluated before POST login so /api/auth?logout=1 cannot fall through.
        if (($method==='POST' && isset($_GET['logout'])) || $method==='DELETE') {
            brvtal_admin_logout();
            json_response(['ok'=>true]);
        }

        if($method==='GET') {
            $authenticated=brvtal_admin_is_authenticated();
            json_response(['ok'=>true,'authenticated'=>$authenticated,'csrf'=>$authenticated?brvtal_admin_csrf_token():null]);
        }

        if($method==='POST') {
            $d=$GLOBALS['brvtal_auth_input'] ?? input_json();
            unset($GLOBALS['brvtal_auth_input']);
            if(($d['action'] ?? '') === 'totp_verify') {
                $pendingId=brvtal_totp_pending_admin_id();
                if($pendingId===null) json_response(['ok'=>false,'error'=>'TOTP_CHALLENGE_EXPIRED'],401);
                brvtal_totp_rate_limit($pendingId);
                $code=trim((string)($d['code']??''));
                if($code==='') json_response(['ok'=>false,'error'=>'TOTP_CODE_REQUIRED'],422);
                $st=db()->prepare('SELECT id,email,name,is_active,totp_enabled,totp_secret_enc FROM admins WHERE id=? LIMIT 1'); $st->execute([$pendingId]); $a=$st->fetch();
                if(!$a || !(int)$a['is_active'] || !(int)$a['totp_enabled']) { brvtal_totp_pending_clear(); json_response(['ok'=>false,'error'=>'TOTP_UNAVAILABLE'],401); }
                $secret=brvtal_totp_decrypt_secret((string)$a['totp_secret_enc']);
                $valid=$secret!==null && brvtal_totp_verify($secret,$code,null,1);
                if(!$valid) $valid=brvtal_totp_recovery_verify(db(),$pendingId,$code);
                if(!$valid) { brvtal_log('SECURITY','Invalid TOTP challenge',['admin_id'=>$pendingId]); json_response(['ok'=>false,'error'=>'INVALID_TOTP'],401); }
                brvtal_totp_complete_login(db(),$a);
            } $email=strtolower(trim((string)($d['email']??''))); $pass=(string)($d['password']??'');
            if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190||$pass==='') json_response(['ok'=>false,'error'=>'EMAIL_AND_PASSWORD_REQUIRED'],422);
            rate_limit_login($email);
            $st=db()->prepare('SELECT id,email,password_hash,name,totp_enabled,totp_secret_enc FROM admins WHERE email=? AND is_active=1 LIMIT 1'); $st->execute([$email]); $a=$st->fetch();
  if(!$a || !password_verify($pass,(string)$a['password_hash'])) { brvtal_log('AUTH_FAIL','Invalid admin login',['email'=>$email]); json_response(['ok'=>false,'error'=>'INVALID_CREDENTIALS'],401); }
  if((int)($a['totp_enabled'] ?? 0) === 1) {
      if(empty($a['totp_secret_enc'])) { brvtal_log('SECURITY','TOTP enabled without encrypted secret',['admin_id'=>(int)$a['id']]); json_response(['ok'=>false,'error'=>'TOTP_CONFIGURATION_ERROR'],503); }
      brvtal_totp_pending_set((int)$a['id'],(string)$a['email']);
      brvtal_log('SECURITY','TOTP challenge issued',['admin_id'=>(int)$a['id']]);
      json_response(['ok'=>true,'requires_totp'=>true,'admin'=>['id'=>(int)$a['id'],'name'=>$a['name'],'email'=>$a['email']]]);
  }
  brvtal_admin_login_session((int)$a['id']);
  db()->prepare('UPDATE admins SET last_login_at=NOW() WHERE id=?')->execute([$a['id']]);
  brvtal_log('AUTH_OK','Admin login successful',['admin_id'=>(int)$a['id']]);
  json_response(['ok'=>true,'admin'=>['id'=>(int)$a['id'],'name'=>$a['name'],'email'=>$a['email']],'csrf'=>brvtal_admin_csrf_token()]);
        }

        method_not_allowed();
    }

    if($resource==='public') {
        if($method!=='GET') method_not_allowed();
        $pdo=db();
        $events=$pdo->query("SELECT id,title,slug,event_date,archive_year,venue,city,description,skin,accent,cover_image,ticket_url,ticket_instructions,ticket_qr,featured,published_at,cancelled_at,finished_at,status FROM events WHERE status='published' ORDER BY event_date ASC,sort_order ASC")->fetchAll();
        $artists=$pdo->query("SELECT id,name,slug,bio,photo,instagram_url,soundcloud_url,website_url,collective_status,collective_order,collective_joined_at,collective_left_at FROM artists WHERE status='published' ORDER BY sort_order ASC,name ASC")->fetchAll();
        $sets=$pdo->query("SELECT s.id,s.title,s.slug,s.artist_id,s.event_id,s.platform,s.external_url,s.embed_url,s.cover_image,s.description,a.name artist_name,e.title event_title FROM sets_media s LEFT JOIN artists a ON a.id=s.artist_id LEFT JOIN events e ON e.id=s.event_id WHERE s.status='published' ORDER BY s.sort_order ASC,s.created_at DESC")->fetchAll();
        $pages=$pdo->query("SELECT id,title,slug,locale,content_json,seo_title,seo_description FROM pages WHERE status='published' ORDER BY id DESC")->fetchAll();
        $ticketTypes=$pdo->query("SELECT id,event_id,name,description,price,currency,external_url,payment_instructions,qr_image,status,available_from,available_until,sort_order FROM event_ticket_types WHERE status IN ('active','sold_out') ORDER BY event_id,sort_order,name")->fetchAll();
        $ticketsByEvent=[]; foreach($ticketTypes as $ticket){$ticketsByEvent[$ticket['event_id']][]=$ticket;}
        foreach($events as &$event){$event['ticket_types']=$ticketsByEvent[$event['id']]??[];} unset($event);
        $settings=$pdo->query('SELECT setting_key,setting_value,is_json FROM settings ORDER BY setting_key')->fetchAll(); foreach($settings as &$row){if((int)$row['is_json']===1)$row['setting_value']=json_decode((string)$row['setting_value'],true);}
        $lineup=$pdo->query("SELECT ea.event_id,ea.artist_id,ea.lineup_order,ea.role,a.name,a.slug,a.photo FROM event_artists ea JOIN artists a ON a.id=ea.artist_id JOIN events e ON e.id=ea.event_id WHERE e.status='published' AND a.status='published' ORDER BY ea.event_id,ea.lineup_order,a.name")->fetchAll();
        $lineupByEvent=[]; foreach($lineup as $item){$lineupByEvent[$item['event_id']][]=$item;}
        foreach($events as &$event){$event['lineup']=$lineupByEvent[$event['id']]??[];}
        $etag='"'.sha1(json_encode([$events,$artists,$sets,$pages,$settings])).'"'; header('ETag: '.$etag); header('Cache-Control: public, max-age=60, stale-while-revalidate=300'); if(trim((string)($_SERVER['HTTP_IF_NONE_MATCH']??''))===$etag){http_response_code(304);exit;}
        json_response(['ok'=>true,'data'=>['events'=>$events,'artists'=>$artists,'sets'=>$sets,'pages'=>$pages,'settings'=>$settings,'generated_at'=>date(DATE_ATOM)]],200);
    }

    brvtal_admin_require();
    if($resource==='dashboard') {
    if($method!=='GET') method_not_allowed();
    $pdo=db(); $counts=[];
    foreach(['events','artists','sets_media','media','pages'] as $t)$counts[$t]=(int)$pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
    $counts['published_events']=(int)$pdo->query("SELECT COUNT(*) FROM events WHERE status='published'")->fetchColumn();
    $counts['published_artists']=(int)$pdo->query("SELECT COUNT(*) FROM artists WHERE status='published'")->fetchColumn();
    $counts['analytics_30d']=(int)$pdo->query("SELECT COUNT(*) FROM analytics_events WHERE created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)")->fetchColumn();
    $counts['db_driver']=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $counts['db_server']=$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    $recent=[];
    $recent['events']=$pdo->query("SELECT id,title,slug,event_date,venue,city,cover_image,status FROM events ORDER BY id DESC LIMIT 5")->fetchAll();
    $recent['artists']=$pdo->query("SELECT id,name,slug,photo,status FROM artists ORDER BY id DESC LIMIT 5")->fetchAll();
    $recent['sets']=$pdo->query("SELECT s.id,s.title,s.slug,s.artist_id,s.event_id,s.platform,s.cover_image,s.status,a.name artist_name,e.title event_title FROM sets_media s LEFT JOIN artists a ON a.id=s.artist_id LEFT JOIN events e ON e.id=s.event_id ORDER BY s.id DESC LIMIT 5")->fetchAll();
    $recent['media']=$pdo->query("SELECT id,type,title,file_path,mime_type,status,created_at FROM media ORDER BY id DESC LIMIT 5")->fetchAll();
    json_response(['ok'=>true,'data'=>$counts,'recent'=>$recent]);
}

    if($resource==='events' && $action==='lineup' && $id!==null) {
        if($method!=='GET'&&$method!=='POST')method_not_allowed(); if($method==='POST')brvtal_admin_require_csrf(); $pdo=db();
        if($method==='GET'){ $st=$pdo->prepare("SELECT ea.artist_id,ea.lineup_order,ea.role,a.name,a.slug,a.photo FROM event_artists ea JOIN artists a ON a.id=ea.artist_id WHERE ea.event_id=? ORDER BY ea.lineup_order ASC,a.name ASC");$st->execute([$id]);json_response(['ok'=>true,'data'=>$st->fetchAll()]); }
        $d=input_json();$items=$d['lineup']??[];if(!is_array($items)||count($items)>200)json_response(['ok'=>false,'error'=>'INVALID_LINEUP'],422);$pdo->beginTransaction();try{$pdo->prepare('DELETE FROM event_artists WHERE event_id=?')->execute([$id]);$st=$pdo->prepare('INSERT INTO event_artists(event_id,artist_id,lineup_order,role) VALUES(?,?,?,?)');foreach($items as $i=>$item){$aid=(int)($item['artist_id']??0);if($aid>0)$st->execute([$id,$aid,$i,mb_substr(trim((string)($item['role']??'')),0,80)]);} $pdo->commit();json_response(['ok'=>true]);}catch(Throwable $e){$pdo->rollBack();brvtal_log('DB_ERROR','Lineup save failed',['event_id'=>$id,'message'=>$e->getMessage()]);json_response(['ok'=>false,'error'=>'LINEUP_SAVE_ERROR'],500);}
    }

    if($resource==='upload') {
        if($method!=='POST')method_not_allowed(); brvtal_admin_require_csrf();
        if(empty($_FILES['file'])||$_FILES['file']['error']!==UPLOAD_ERR_OK)json_response(['ok'=>false,'error'=>'UPLOAD_REQUIRED'],422);
        $f=$_FILES['file']; if(!is_uploaded_file($f['tmp_name']))json_response(['ok'=>false,'error'=>'INVALID_UPLOAD'],422); $max=25*1024*1024; if((int)$f['size']<=0||$f['size']>$max)json_response(['ok'=>false,'error'=>'FILE_TOO_LARGE'],422);
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','video/mp4'=>'mp4','audio/mpeg'=>'mp3','audio/wav'=>'wav','application/pdf'=>'pdf'];
        if(!isset($allowed[$mime]))json_response(['ok'=>false,'error'=>'FILE_TYPE_NOT_ALLOWED'],422);
        if(str_starts_with($mime,'image/') && @getimagesize($f['tmp_name'])===false)json_response(['ok'=>false,'error'=>'INVALID_IMAGE'],422);
        $folder=strtolower(trim((string)($_POST['folder']??'media'),'/')); if(!preg_match('/^[a-z0-9_-]{1,40}$/',$folder))$folder='media';
        $root=__DIR__.'/../uploads/'.$folder; if(!is_dir($root)&&!mkdir($root,0750,true)&&!is_dir($root))json_response(['ok'=>false,'error'=>'UPLOAD_STORAGE_ERROR'],500);
        $name=bin2hex(random_bytes(16)).'.'.$allowed[$mime];$dest=$root.'/'.$name; if(!move_uploaded_file($f['tmp_name'],$dest))json_response(['ok'=>false,'error'=>'UPLOAD_FAILED'],500); @chmod($dest,0640);
        $public='/uploads/'.$folder.'/'.$name; $type=str_starts_with($mime,'image/')?'image':(str_starts_with($mime,'video/')?'video':(str_starts_with($mime,'audio/')?'audio':'document')); $st=db()->prepare('INSERT INTO media(type,title,file_path,mime_type,file_size,alt_text,status) VALUES(?,?,?,?,?,?,?)');$title=mb_substr(trim((string)($_POST['title']??'Media')),0,180);$alt=mb_substr(trim((string)($_POST['alt_text']??'')),0,255);$st->execute([$type,$title,$public,$mime,(int)$f['size'],$alt,'published']);json_response(['ok'=>true,'data'=>['id'=>(int)db()->lastInsertId(),'path'=>$public,'mime_type'=>$mime,'size'=>(int)$f['size']]],201);
    }

    $resources=['events','artists','sets','media','pages','ticket_types','settings']; if(!in_array($resource,$resources,true))json_response(['ok'=>false,'error'=>'NOT_FOUND'],404); $table=table_for($resource); if($method!=='GET')brvtal_admin_require_csrf(); $pdo=db();
    if($method==='GET') { if($resource==='settings')$rows=$pdo->query('SELECT * FROM settings ORDER BY setting_key')->fetchAll(); else if($id!==null){$st=$pdo->prepare("SELECT * FROM {$table} WHERE id=? LIMIT 1");$st->execute([$id]);$rows=$st->fetch();if(!$rows)json_response(['ok'=>false,'error'=>'NOT_FOUND'],404);} else $rows=$pdo->query("SELECT * FROM {$table} ORDER BY id DESC")->fetchAll();json_response(['ok'=>true,'data'=>$rows]); }
    if($method==='POST') {
        $d=input_json(); if($resource==='settings'){ $key=trim((string)($d['setting_key']??''));$key=preg_replace('/[^a-zA-Z0-9_.-]/','',$key)??'';if($key===''||strlen($key)>120)json_response(['ok'=>false,'error'=>'KEY_REQUIRED'],422);$value=(string)($d['setting_value']??'');if(strlen($value)>2*1024*1024)json_response(['ok'=>false,'error'=>'VALUE_TOO_LARGE'],422);if((int)($d['is_json']??0)===1&&json_decode($value,true)===null&&strtolower(trim($value))!=='null')json_response(['ok'=>false,'error'=>'INVALID_SETTING_JSON'],422);$st=$pdo->prepare('INSERT INTO settings(setting_key,setting_value,is_json) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_json=VALUES(is_json)');$st->execute([$key,$value,(int)($d['is_json']??0)]);json_response(['ok'=>true]); }
        $d=sanitize_payload($resource,$d);$allowed=allowed_fields($resource);$p=[];foreach($allowed as $f)if(array_key_exists($f,$d))$p[$f]=$d[$f]; if($resource==='events'&&empty($p['title']))json_response(['ok'=>false,'error'=>'TITLE_REQUIRED'],422);if($resource==='artists'&&empty($p['name']))json_response(['ok'=>false,'error'=>'NAME_REQUIRED'],422);if($resource==='sets'&&empty($p['title']))json_response(['ok'=>false,'error'=>'TITLE_REQUIRED'],422);if(isset($p['slug'])&&$p['slug']==='')$p['slug']=slugify((string)($p['title']??$p['name']??'item'));if(!$p)json_response(['ok'=>false,'error'=>'NO_FIELDS'],422);$fields=array_keys($p);$cols=implode(',',array_map(fn($f)=>"`{$f}`",$fields));$marks=implode(',',array_fill(0,count($fields),'?'));try{$st=$pdo->prepare("INSERT INTO {$table} ({$cols}) VALUES ({$marks})");$st->execute(array_values($p));json_response(['ok'=>true,'id'=>(int)$pdo->lastInsertId()],201);}catch(PDOException $e){brvtal_log('DB_ERROR','Insert failed',['resource'=>$resource,'code'=>$e->errorInfo[1]??null]);if((int)($e->errorInfo[1]??0)===1062)json_response(['ok'=>false,'error'=>'DUPLICATE_SLUG'],409);json_response(['ok'=>false,'error'=>'DATABASE_ERROR'],500);}
    }
    if($method==='PUT'&&$id!==null){$d=sanitize_payload($resource,input_json());$allowed=allowed_fields($resource);$p=[];foreach($allowed as $f)if(array_key_exists($f,$d))$p[$f]=$d[$f];if(!$p)json_response(['ok'=>false,'error'=>'NO_FIELDS'],422);$set=implode(', ',array_map(fn($f)=>"`{$f}` = ?",array_keys($p)));$vals=array_values($p);$vals[]=$id;try{$st=$pdo->prepare("UPDATE {$table} SET {$set} WHERE id=?");$st->execute($vals);json_response(['ok'=>true,'changed'=>(int)$st->rowCount()]);}catch(PDOException $e){brvtal_log('DB_ERROR','Update failed',['resource'=>$resource,'id'=>$id,'code'=>$e->errorInfo[1]??null]);if((int)($e->errorInfo[1]??0)===1062)json_response(['ok'=>false,'error'=>'DUPLICATE_SLUG'],409);json_response(['ok'=>false,'error'=>'DATABASE_ERROR'],500);}}
    if($method==='DELETE'&&$id!==null){$st=$pdo->prepare("DELETE FROM {$table} WHERE id=?");$st->execute([$id]);json_response(['ok'=>true,'deleted'=>(int)$st->rowCount()]);}
    if($method==='DELETE'&&$resource==='settings'&&$id===null){$key=preg_replace('/[^a-zA-Z0-9_.-]/','',(string)($_GET['key']??''))??'';if($key==='')json_response(['ok'=>false,'error'=>'KEY_REQUIRED'],422);$st=$pdo->prepare('DELETE FROM settings WHERE setting_key=?');$st->execute([$key]);json_response(['ok'=>true,'deleted'=>(int)$st->rowCount()]);}
    method_not_allowed();
} catch(Throwable $e) { handle_exception($e); }
