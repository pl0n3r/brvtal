<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin_activity.php';
require_once __DIR__ . '/../config/event_lifecycle.php';
require_once __DIR__ . '/../config/set_publication.php';
require_once __DIR__ . '/../config/totp_auth.php';
require_once __DIR__ . '/../config/password_rate_limit.php';
require_once __DIR__ . '/../config/indexnow.php';
require_once __DIR__ . '/route.php';
require_once __DIR__ . '/pages-contract.php';
require_once __DIR__ . '/content-validation.php';

function client_key(): string {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash('sha256', $ip . '|' . strtolower((string)($_POST['email'] ?? '')));
}

function rate_limit_login(string $email, bool $recordFailure = false): void {
    $state = $recordFailure
        ? brvtal_password_rate_limit_failure($email)
        : brvtal_password_rate_limit_check($email);
    if (!$state['limited']) return;
    $retryAfter = max(1, (int)$state['retry_after']);
    json_response(
        ['ok'=>false,'error'=>'RATE_LIMITED','retry_after'=>$retryAfter],
        429,
        ['Retry-After'=>(string)$retryAfter]
    );
}

function reset_login_rate_limit(string $email): void {
    brvtal_password_rate_limit_reset($email);
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
    if($resource==='pages' && array_key_exists('content_json',$d)) { $pageContentError=brvtal_page_content_json_error($d['content_json']); if($pageContentError!==null) json_response(['ok'=>false,'error'=>$pageContentError,'field'=>'content_json'],422); }
    $temporal=brvtal_content_temporal_normalize($resource,$d);
    if($temporal['error']!==null) json_response(['ok'=>false,'error'=>$temporal['error']['error'],'field'=>$temporal['error']['field']],422);
    return $temporal['payload'];
}
function allowed_fields(string $resource): array {
    return ['events'=>['title','slug','event_date','venue','city','description','skin','accent','cover_image','ticket_url','ticket_instructions','ticket_qr','featured','archive_year','status','sort_order'],'artists'=>['name','slug','bio','photo','instagram_url','soundcloud_url','website_url','collective_status','collective_order','collective_joined_at','collective_left_at','status','sort_order'],'sets'=>['title','slug','artist_id','event_id','platform','external_url','embed_url','cover_image','description','status','sort_order'],'media'=>['type','title','file_path','mime_type','file_size','alt_text','status'],'pages'=>['title','slug','locale','content_json','seo_title','seo_description','status'],'ticket_types'=>['event_id','name','description','price','currency','external_url','payment_instructions','qr_image','status','available_from','available_until','sort_order']][$resource] ?? [];
}
function table_for(string $resource): string { return ['events'=>'events','artists'=>'artists','sets'=>'sets_media','media'=>'media','pages'=>'pages','ticket_types'=>'event_ticket_types','settings'=>'settings'][$resource] ?? ''; }
function brvtal_activity_audited_resource(string $resource): bool { return in_array($resource,['events','artists','sets','pages','ticket_types'],true); }
function brvtal_activity_fetch_resource(PDO $pdo, string $table, int $id, bool $lock = false): ?array {
    $sql="SELECT * FROM {$table} WHERE id=? LIMIT 1" . ($lock ? ' FOR UPDATE' : '');
    $st=$pdo->prepare($sql);$st->execute([$id]);$row=$st->fetch(PDO::FETCH_ASSOC);return $row?:null;
}
/** Acquire the cross-session database mutex that serializes Theme setting references. */
function brvtalAcquireThemeReferenceMutex(PDO $pdo): void
{
    $st = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $st->execute(['brvtal.theme_reference']);
    if ((int)$st->fetchColumn() !== 1) {
        json_response(['ok' => false, 'error' => 'THEME_SETTINGS_BUSY'], 503);
    }
}

/** Release the Theme reference mutex without masking the request result. */
function brvtalReleaseThemeReferenceMutex(PDO $pdo): void
{
    try {
        $st = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $st->execute(['brvtal.theme_reference']);
        $st->fetchColumn();
    } catch (Throwable $e) {
        brvtal_log('THEME_SETTINGS_LOCK_RELEASE_FAILED', 'Could not release Theme settings mutex', [
            'class' => get_class($e),
        ]);
    }
}

function handle_exception(Throwable $e): never {
    if($e instanceof RuntimeException && $e->getMessage()==='ACTIVITY_SCHEMA_MISSING') json_response(['ok'=>false,'error'=>'ACTIVITY_SCHEMA_MISSING'],503);
    brvtal_log('API_ERROR','Unhandled API exception',['class'=>get_class($e),'message'=>$e->getMessage(),'line'=>$e->getLine()]); json_response(['ok'=>false,'error'=>'INTERNAL_ERROR'],500);
}

try {
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    $route=brvtal_api_parse_route((string)($_SERVER['REQUEST_URI']??'/'),(string)($_SERVER['SCRIPT_NAME']??''));
    $segments=$route['segments'];
    $resource=$route['resource'];
    $id=$route['id'];
    $action=$route['action'];

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
  if(!$a || !password_verify($pass,(string)$a['password_hash'])) { brvtal_log('AUTH_FAIL','Invalid admin login',['email'=>$email]); rate_limit_login($email,true); json_response(['ok'=>false,'error'=>'INVALID_CREDENTIALS'],401); }
  reset_login_rate_limit($email);
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
        require __DIR__ . '/public.php';
        exit;
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
        $d=input_json();$items=$d['lineup']??[];if(!is_array($items)||count($items)>200)json_response(['ok'=>false,'error'=>'INVALID_LINEUP'],422);
        $pdo->beginTransaction();
        try {
            $eventLock=$pdo->prepare('SELECT title FROM events WHERE id=? LIMIT 1 FOR UPDATE');$eventLock->execute([$id]);$eventTitle=$eventLock->fetchColumn();if($eventTitle===false)json_response(['ok'=>false,'error'=>'NOT_FOUND'],404);
            $beforeSt=$pdo->prepare('SELECT artist_id,lineup_order,role FROM event_artists WHERE event_id=? ORDER BY lineup_order,artist_id');$beforeSt->execute([$id]);$beforeLineup=$beforeSt->fetchAll(PDO::FETCH_ASSOC);
            $pdo->prepare('DELETE FROM event_artists WHERE event_id=?')->execute([$id]);
            $st=$pdo->prepare('INSERT INTO event_artists(event_id,artist_id,lineup_order,role) VALUES(?,?,?,?)');
            foreach($items as $i=>$item){$aid=(int)($item['artist_id']??0);if($aid>0)$st->execute([$id,$aid,$i,mb_substr(trim((string)($item['role']??'')),0,80)]);}
            $afterSt=$pdo->prepare('SELECT artist_id,lineup_order,role FROM event_artists WHERE event_id=? ORDER BY lineup_order,artist_id');$afterSt->execute([$id]);$afterLineup=$afterSt->fetchAll(PDO::FETCH_ASSOC);
            $before=['event_id'=>$id,'lineup'=>$beforeLineup];$after=['event_id'=>$id,'lineup'=>$afterLineup];
            if(brvtal_activity_changed_fields(brvtal_activity_snapshot('event_lineup',$before),brvtal_activity_snapshot('event_lineup',$after))!==[]){brvtal_activity_record($pdo,'lineup_update','event_lineup',$id,$before,$after,['source'=>'lineup_api'],(string)$eventTitle);}
            $pdo->commit();brvtal_indexnow_notify_event_id($pdo,$id);json_response(['ok'=>true]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($e instanceof RuntimeException&&$e->getMessage()==='ACTIVITY_SCHEMA_MISSING')throw $e;brvtal_log('DB_ERROR','Lineup save failed',['event_id'=>$id,'message'=>$e->getMessage()]);json_response(['ok'=>false,'error'=>'LINEUP_SAVE_ERROR'],500);}
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
    if($method==='GET') { if($resource==='settings')$rows=$pdo->query("SELECT * FROM settings WHERE setting_key<>'security.totp_encryption_key' ORDER BY setting_key")->fetchAll(); else if($id!==null){$st=$pdo->prepare("SELECT * FROM {$table} WHERE id=? LIMIT 1");$st->execute([$id]);$rows=$st->fetch();if(!$rows)json_response(['ok'=>false,'error'=>'NOT_FOUND'],404);} else $rows=$pdo->query("SELECT * FROM {$table} ORDER BY id DESC")->fetchAll();json_response(['ok'=>true,'data'=>$rows]); }
    if($method==='POST') {
        $d=input_json();
        if ($resource === 'settings') {
            $keyState = brvtal_setting_key_normalize((string)($d['setting_key'] ?? ''));
            if ($keyState['error'] !== null) {
                json_response(
                    ['ok' => false, 'error' => $keyState['error']['error'], 'field' => $keyState['error']['field']],
                    422
                );
            }

            $key = (string)$keyState['key'];
            if ($key === '' || strlen($key) > 120) {
                json_response(['ok' => false, 'error' => 'KEY_REQUIRED'], 422);
            }
            if ($key === 'security.totp_encryption_key') {
                json_response(['ok' => false, 'error' => 'PROTECTED_SETTING'], 403);
            }

            $value = (string)($d['setting_value'] ?? '');
            $themeSettingError = brvtal_theme_setting_error($key, $value);
            if ($themeSettingError !== null) {
                json_response(
                    ['ok' => false, 'error' => $themeSettingError['error'], 'field' => $themeSettingError['field']],
                    422
                );
            }

            if (strlen($value) > 2 * 1024 * 1024) {
                json_response(['ok' => false, 'error' => 'VALUE_TOO_LARGE'], 422);
            }
            $isJson = (int)($d['is_json'] ?? 0);
            $invalidJson = $isJson === 1
                && json_decode($value, true) === null
                && strtolower(trim($value)) !== 'null';
            if ($invalidJson) {
                json_response(['ok' => false, 'error' => 'INVALID_SETTING_JSON'], 422);
            }
            if ($key === BRVTAL_INDEXNOW_SETTING_KEY) {
                $indexNowError = brvtal_indexnow_setting_error($value, $isJson);
                if ($indexNowError !== null) {
                    json_response(
                        ['ok' => false, 'error' => $indexNowError['error'], 'field' => $indexNowError['field']],
                        422
                    );
                }
            }

            $themeMutation = str_starts_with($key, 'theme.');
            if ($themeMutation) {
                brvtalAcquireThemeReferenceMutex($pdo);
            }

            try {
                if ($themeMutation) {
                    $pdo->beginTransaction();

                    $activeTheme = $pdo->query(
                        "SELECT setting_value FROM settings WHERE setting_key='theme.active' LIMIT 1 FOR UPDATE"
                    )->fetchColumn();
                    $themeDefinitionUpdateError = brvtalThemeDefinitionUpdateError(
                        $key,
                        $isJson,
                        $value,
                        is_string($activeTheme) ? trim($activeTheme) : null
                    );
                    if ($themeDefinitionUpdateError !== null) {
                        $pdo->rollBack();
                        brvtalReleaseThemeReferenceMutex($pdo);
                        json_response(
                            [
                                'ok' => false,
                                'error' => $themeDefinitionUpdateError['error'],
                                'field' => $themeDefinitionUpdateError['field'],
                            ],
                            422
                        );
                    }
                }

                $themeReferenceError = brvtalThemeActiveReferenceError(
                    $key,
                    $value,
                    static function (string $slug) use ($pdo): bool {
                        $st = $pdo->prepare(
                            'SELECT setting_value FROM settings WHERE setting_key=? AND is_json=1 LIMIT 1 FOR UPDATE'
                        );
                        $st->execute(['theme.' . $slug]);
                        $raw = $st->fetchColumn();
                        if (!is_string($raw)) {
                            return false;
                        }
                        $decoded = json_decode($raw, true);
                        return is_array($decoded);
                    }
                );
                if ($themeReferenceError !== null) {
                    if ($themeMutation && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($themeMutation) {
                        brvtalReleaseThemeReferenceMutex($pdo);
                    }
                    json_response(
                        [
                            'ok' => false,
                            'error' => $themeReferenceError['error'],
                            'field' => $themeReferenceError['field'],
                        ],
                        422
                    );
                }

                $st = $pdo->prepare(
                    'INSERT INTO settings(setting_key,setting_value,is_json) VALUES(?,?,?) '
                    . 'ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_json=VALUES(is_json)'
                );
                $st->execute([$key, $value, $isJson]);

                if ($themeMutation) {
                    $pdo->commit();
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($themeMutation) {
                    brvtalReleaseThemeReferenceMutex($pdo);
                }
                throw $e;
            }

            if ($themeMutation) {
                brvtalReleaseThemeReferenceMutex($pdo);
            }
            brvtal_indexnow_notify_setting($pdo, $key);
            json_response(['ok' => true]);
        }
        $d=sanitize_payload($resource,$d);$allowed=allowed_fields($resource);$p=[];foreach($allowed as $f)if(array_key_exists($f,$d))$p[$f]=$d[$f];if($resource==='events')$p=brvtal_event_lifecycle_patch([],$p);if($resource==='events'){$eventStateError=brvtal_event_publication_error(array_replace(['status'=>'draft'],$p));if($eventStateError!==null)json_response(['ok'=>false,'error'=>$eventStateError['error'],'field'=>$eventStateError['field']],422);} if($resource==='pages'&&!array_key_exists('locale',$p))$p['locale']='en';if($resource==='pages'){if(!array_key_exists('slug',$p)||$p['slug']==='')$p['slug']=slugify((string)($p['title']??''));$pageIdentityError=brvtal_page_identity_error($p);if($pageIdentityError!==null)json_response(['ok'=>false,'error'=>$pageIdentityError['error'],'field'=>$pageIdentityError['field']],422);$pageStateError=brvtal_page_publication_error(array_replace(['status'=>'draft','locale'=>'en'],$p)); if($pageStateError!==null)json_response(['ok'=>false,'error'=>$pageStateError,'field'=>'locale'],422); }if($resource==='ticket_types'){$ticketWindowError=brvtal_ticket_window_error($p);if($ticketWindowError!==null)json_response(['ok'=>false,'error'=>$ticketWindowError['error'],'field'=>$ticketWindowError['field']],422);}if($resource==='sets'){ $setPublicationError=brvtal_set_publication_error(array_replace(['status'=>'draft','external_url'=>''],$p)); if($setPublicationError!==null)json_response(['ok'=>false,'error'=>$setPublicationError,'field'=>'external_url'],422); }if($resource==='events'&&empty($p['title']))json_response(['ok'=>false,'error'=>'TITLE_REQUIRED'],422);if($resource==='artists'&&empty($p['name']))json_response(['ok'=>false,'error'=>'NAME_REQUIRED'],422);if($resource==='sets'&&empty($p['title']))json_response(['ok'=>false,'error'=>'TITLE_REQUIRED'],422);if(isset($p['slug'])&&$p['slug']==='')$p['slug']=slugify((string)($p['title']??$p['name']??'item'));if(!$p)json_response(['ok'=>false,'error'=>'NO_FIELDS'],422);$fields=array_keys($p);$cols=implode(',',array_map(fn($f)=>"`{$f}`",$fields));$marks=implode(',',array_fill(0,count($fields),'?'));
        $audited=brvtal_activity_audited_resource($resource);if($audited)$pdo->beginTransaction();
        try{$st=$pdo->prepare("INSERT INTO {$table} ({$cols}) VALUES ({$marks})");$st->execute(array_values($p));$newId=(int)$pdo->lastInsertId();if($audited){$after=brvtal_activity_fetch_resource($pdo,$table,$newId);brvtal_activity_record($pdo,'create',$resource,$newId,null,$after,['source'=>'core_api']);$pdo->commit();brvtal_indexnow_notify_change($pdo,$resource,null,$after);}json_response(['ok'=>true,'id'=>$newId],201);}catch(PDOException $e){if($pdo->inTransaction())$pdo->rollBack();brvtal_log('DB_ERROR','Insert failed',['resource'=>$resource,'code'=>$e->errorInfo[1]??null]);if((int)($e->errorInfo[1]??0)===1062)json_response(['ok'=>false,'error'=>'DUPLICATE_SLUG'],409);json_response(['ok'=>false,'error'=>'DATABASE_ERROR'],500);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    if($method==='PUT'&&$id!==null){
        $d=sanitize_payload($resource,input_json());$allowed=allowed_fields($resource);$p=[];foreach($allowed as $f)if(array_key_exists($f,$d))$p[$f]=$d[$f];if(!$p)json_response(['ok'=>false,'error'=>'NO_FIELDS'],422);
        $audited=brvtal_activity_audited_resource($resource);$pdo->beginTransaction();
        try{
            $before=brvtal_activity_fetch_resource($pdo,$table,$id,true);
            if($before===null){$pdo->rollBack();json_response(['ok'=>false,'error'=>'NOT_FOUND'],404);}
            if($resource==='events')$p=brvtal_event_lifecycle_patch($before,$p);if($resource==='events'){$eventStateError=brvtal_event_publication_error(array_replace($before,$p));if($eventStateError!==null){$pdo->rollBack();json_response(['ok'=>false,'error'=>$eventStateError['error'],'field'=>$eventStateError['field']],422);}}
            if($resource==='pages'){$pageState=array_replace($before,$p);if(array_key_exists('slug',$p)&&$p['slug']===''){$p['slug']=slugify((string)($pageState['title']??''));$pageState=array_replace($before,$p);}$pageIdentityError=brvtal_page_identity_error($pageState);if($pageIdentityError!==null){$pdo->rollBack();json_response(['ok'=>false,'error'=>$pageIdentityError['error'],'field'=>$pageIdentityError['field']],422);}$pageStateError=brvtal_page_publication_error($pageState);if($pageStateError!==null){$pdo->rollBack();json_response(['ok'=>false,'error'=>$pageStateError,'field'=>'locale'],422);}}
            if($resource==='ticket_types'){$ticketWindowError=brvtal_ticket_window_error(array_replace($before,$p));if($ticketWindowError!==null){$pdo->rollBack();json_response(['ok'=>false,'error'=>$ticketWindowError['error'],'field'=>$ticketWindowError['field']],422);}}
            if($resource==='sets'){$setPublicationError=brvtal_set_publication_error(array_replace($before,$p));if($setPublicationError!==null){$pdo->rollBack();json_response(['ok'=>false,'error'=>$setPublicationError,'field'=>'external_url'],422);}}
            if(!$p){$pdo->rollBack();json_response(['ok'=>false,'error'=>'NO_FIELDS'],422);}
            $set=implode(', ',array_map(fn($f)=>"`{$f}` = ?",array_keys($p)));$vals=array_values($p);$vals[]=$id;$st=$pdo->prepare("UPDATE {$table} SET {$set} WHERE id=?");$st->execute($vals);$changed=(int)$st->rowCount();
            if($audited){$after=brvtal_activity_fetch_resource($pdo,$table,$id);if($after!==null)brvtal_activity_record($pdo,'update',$resource,$id,$before,$after,['source'=>'core_api']);}
            $pdo->commit();
            if($audited && $changed>0 && $after!==null)brvtal_indexnow_notify_change($pdo,$resource,$before,$after);
            json_response(['ok'=>true,'changed'=>$changed]);
        }catch(PDOException $e){if($pdo->inTransaction())$pdo->rollBack();brvtal_log('DB_ERROR','Update failed',['resource'=>$resource,'id'=>$id,'code'=>$e->errorInfo[1]??null]);if((int)($e->errorInfo[1]??0)===1062)json_response(['ok'=>false,'error'=>'DUPLICATE_SLUG'],409);json_response(['ok'=>false,'error'=>'DATABASE_ERROR'],500);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    if($method==='DELETE'&&$id!==null){
        $audited=brvtal_activity_audited_resource($resource);$pdo->beginTransaction();
        try{
            $before=brvtal_activity_fetch_resource($pdo,$table,$id,true);
            if($before===null){$pdo->rollBack();json_response(['ok'=>false,'error'=>'NOT_FOUND'],404);}
            $st=$pdo->prepare("DELETE FROM {$table} WHERE id=?");$st->execute([$id]);$deleted=(int)$st->rowCount();
            if($deleted!==1){$pdo->rollBack();json_response(['ok'=>false,'error'=>'NOT_FOUND'],404);}
            if($audited)brvtal_activity_record($pdo,'delete',$resource,$id,$before,null,['source'=>'core_api']);
            $pdo->commit();
            if($audited)brvtal_indexnow_notify_change($pdo,$resource,$before,null);
            json_response(['ok'=>true,'deleted'=>1]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    if ($method === 'DELETE' && $resource === 'settings' && $id === null) {
        $keyState = brvtal_setting_key_normalize((string)($_GET['key'] ?? ''));
        if ($keyState['error'] !== null) {
            json_response(
                ['ok' => false, 'error' => $keyState['error']['error'], 'field' => $keyState['error']['field']],
                422
            );
        }

        $key = (string)$keyState['key'];
        if ($key === '') {
            json_response(['ok' => false, 'error' => 'KEY_REQUIRED'], 422);
        }
        if ($key === 'security.totp_encryption_key') {
            json_response(['ok' => false, 'error' => 'PROTECTED_SETTING'], 403);
        }

        $themeMutation = str_starts_with($key, 'theme.');
        if ($themeMutation) {
            brvtalAcquireThemeReferenceMutex($pdo);
        }

        try {
            if ($themeMutation) {
                $pdo->beginTransaction();

                $activeTheme = $pdo->query(
                    "SELECT setting_value FROM settings WHERE setting_key='theme.active' LIMIT 1 FOR UPDATE"
                )->fetchColumn();
                $themeDeleteError = brvtalThemeDeleteReferenceError(
                    $key,
                    is_string($activeTheme) ? trim($activeTheme) : null
                );
                if ($themeDeleteError !== null) {
                    $pdo->rollBack();
                    brvtalReleaseThemeReferenceMutex($pdo);
                    json_response(
                        ['ok' => false, 'error' => $themeDeleteError['error'], 'field' => $themeDeleteError['field']],
                        409
                    );
                }
            }

            $st = $pdo->prepare('DELETE FROM settings WHERE setting_key=?');
            $st->execute([$key]);
            $deleted = (int)$st->rowCount();

            if ($themeMutation) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($themeMutation) {
                brvtalReleaseThemeReferenceMutex($pdo);
            }
            throw $e;
        }

        if ($themeMutation) {
            brvtalReleaseThemeReferenceMutex($pdo);
        }
        if ($deleted > 0) {
            brvtal_indexnow_notify_setting($pdo, $key);
        }
        json_response(['ok' => true, 'deleted' => $deleted]);
    }
    method_not_allowed();
} catch(Throwable $e) { handle_exception($e); }