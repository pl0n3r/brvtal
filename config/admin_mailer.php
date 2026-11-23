<?php
declare(strict_types=1);
require_once __DIR__ . '/secrets.php';

require_once __DIR__ . '/mail_template_renderer.php';

/**
 * Published staff-only copy and immutable revision; never store reset links
 * or tokens in an editorial document.
 * @return array{document:array,version:int}|null
 */
function brvtalAdminMailPublished(PDO $pdo, string $key): ?array
{
    brvtal_mail_template_definition($key, 'staff');
    try {
        $q=$pdo->prepare("SELECT v.id,v.document_json FROM mail_template_registry r
            JOIN mail_template_versions v ON v.id=r.published_version_id
            AND v.template_id=r.id AND v.first_published_at IS NOT NULL
            AND v.discarded_at IS NULL
            WHERE r.scope='staff' AND r.template_key=? LIMIT 1");
        $q->execute([$key]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        if (in_array((string)$e->getCode(), ['42S02','42S22'],true)) return null;
        throw $e;
    }
    if (!is_array($row)) return null;
    try {
        $raw=json_decode((string)$row['document_json'],true,64,JSON_THROW_ON_ERROR);
        if (!is_array($raw)) throw new RuntimeException('MAIL_TEMPLATE_DOCUMENT_CORRUPTED');
        $doc=brvtal_mail_template_validate($key,'staff',$raw,
            static fn(int $id): bool => brvtal_mail_delivery_media($pdo,$id)!==null);
    } catch (Throwable) {
        throw new RuntimeException('MAIL_TEMPLATE_DOCUMENT_CORRUPTED');
    }
    return ['document'=>$doc,'version'=>(int)$row['id']];
}

/**
 * Only trusted backend action URLs may be embedded into rendered HTML.
 * Template editors cannot set a destination or create a bearer.
 * @return array{subject:string,text:string,html:string,template_key:string,template_version:int}|null
 */
function brvtalAdminMailRenderPublished(PDO $pdo, string $key, string $actionUrl): ?array
{
    $published=brvtalAdminMailPublished($pdo,$key);
    if ($published===null) return null;
    $parts=parse_url($actionUrl);
    $host=is_array($parts) ? strtolower((string)($parts['host']??'')) : '';
    $testing=getenv('BRVTAL_MAIL_TESTING')==='1' && $host==='brvtal.invalid';
    $expectedPath=$key==='staff_password_recovery' ? '/discadmin/reset-password.php' : '/discadmin';
    $fragment=is_array($parts) ? (string)($parts['fragment']??'') : '';
    if (!is_array($parts) || ($parts['scheme']??'')!=='https'
        || (!$testing && !in_array($host,['brvtal.com.co','www.brvtal.com.co'],true))
        || ($parts['path']??'')!==$expectedPath
        || isset($parts['query']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['port']) || strlen($actionUrl)>2048
        || strpbrk($actionUrl,"\r\n\\")!==false
        || ($key==='staff_password_recovery'
            ? !preg_match('/^token=[a-f0-9]{64}$/D',$fragment) : $fragment!=='')) {
        throw new DomainException('ADMIN_MAIL_LINK_INVALID');
    }
    $copy=$published['document'];
    $theme=$copy['theme'];
    $escape=static fn(string $x): string => htmlspecialchars($x,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $logo=brvtal_mail_delivery_media($pdo,$theme['logo_media_id']);
    $hero=brvtal_mail_delivery_media($pdo,$theme['hero_media_id']);
    $logoMarkup=$logo===null ? '<strong style="letter-spacing:.12em">BRVTAL</strong>'
        : '<img alt="BRVTAL" width="145" src="'.$escape($logo).'" style="max-width:145px;height:auto">';
    $heroMarkup=$hero===null ? ''
        : '<p><img alt="" width="560" src="'.$escape($hero).'" style="max-width:100%;height:auto"></p>';
    $text=$copy['title']."\n\n".$copy['message']."\n\n"
        .$copy['cta'].': '.$actionUrl."\n\n".$copy['footer'];
    $html='<!doctype html><html lang="es"><head><meta charset="utf-8">'
        .'<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        .'<body style="margin:0;padding:0;background:'.$theme['background']
        .';color:'.$theme['foreground'].';font-family:Arial,sans-serif">'
        .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
        .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px">'
        .'<tr><td style="padding:28px 20px;line-height:1.5">'
        .'<div style="display:none!important;max-height:0;overflow:hidden;opacity:0">'
        .$escape($copy['preheader']).'</div>'
        .$logoMarkup.$heroMarkup
        .'<h1 style="font-size:26px;line-height:1.3">'.$escape($copy['title']).'</h1>'
        .'<p style="white-space:pre-line;line-height:1.6">'.nl2br($escape($copy['message']),false).'</p>'
        .'<p style="padding:18px 0"><a href="'.$escape($actionUrl).'"'
        .' style="display:inline-block;padding:14px 18px;background:'.$theme['accent']
        .';color:'.$theme['background'].';text-decoration:underline;font-weight:bold">'
        .$escape($copy['cta']).'</a></p>'
        .'<p style="font-size:13px;line-height:1.5">'.$escape($copy['footer']).'</p>'
        .'</td></tr></table></td></tr></table></body></html>';
    if ($copy['subject']==='' || strlen($text)>50000 || strlen($html)>150000)
        throw new RuntimeException('ADMIN_MAIL_TEMPLATE_RENDER_INVALID');
    return ['subject'=>$copy['subject'],'text'=>$text,'html'=>$html,
        'template_key'=>$key,'template_version'=>$published['version']];
}

/** A security-only receipt: no email address, password, link or token in logs. */
function brvtalAdminMailLogResult(?string $key, ?int $version, bool $ok): void
{
    if ($key!==null && function_exists('brvtal_log')) {
        brvtal_log('ADMIN_MAIL_TEMPLATE_DELIVERY','Staff security mail result',[
            'template_key'=>$key,'template_version'=>$version,
            'state'=>$ok ? 'accepted' : 'failed',
        ]);
    }
}


function brvtalAdminMailCapture(
    string $email, string $subject, string $body, string $purpose,
    ?string $html = null, ?string $templateKey = null, ?int $templateVersion = null
): ?bool
{
    $transport = $GLOBALS['brvtal_password_mail_transport'] ?? null;
    if (is_callable($transport)) {
        return (bool)$transport($email, $subject, $body, $purpose, $html, $templateKey, $templateVersion);
    }
    if (getenv('BRVTAL_MAIL_TESTING') !== '1') {
        return null;
    }
    $path = trim((string)getenv('BRVTAL_MAIL_CAPTURE_FILE'));
    if ($path === '') {
        return false;
    }
    $record = json_encode([
        'email' => $email,
        'subject' => $subject,
        'body' => $body,
        'purpose' => $purpose,
        'html' => $html,
        'template_key' => $templateKey,
        'template_version' => $templateVersion,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($record)
        && file_put_contents($path, $record . "\n", FILE_APPEND | LOCK_EX) !== false;
}

function brvtalAdminMailSend(
    string $email,
    string $subject,
    string $body,
    string $purpose = 'security_notification',
    ?PDO $templatePdo = null,
    ?string $serverActionUrl = null
): bool {
    $templateKey = match (true) {
        $purpose === 'password_recovery' => 'staff_password_recovery',
        str_starts_with($purpose,'password_changed_') => 'staff_password_changed',
        default => null,
    };
    $templateVersion = null;
    $html = null;
    if ($templateKey !== null && $serverActionUrl !== null) {
        $pdo = $templatePdo;
        if ($pdo === null && function_exists('db')) {
            try { $pdo = db(); }
            catch (Throwable) { $pdo = null; }
        }
        if ($pdo instanceof PDO) {
            try {
                $rendered = brvtalAdminMailRenderPublished($pdo,$templateKey,$serverActionUrl);
                if ($rendered !== null) {
                    $subject = $rendered['subject'];
                    $body = $rendered['text'];
                    $html = $rendered['html'];
                    $templateVersion = $rendered['template_version'];
                }
            } catch (Throwable) {
                // Published corrupt document and unsafe backend URL fail closed.
                brvtalAdminMailLogResult($templateKey,null,false);
                return false;
            }
        }
    }
    $capture = brvtalAdminMailCapture(
        $email,$subject,$body,$purpose,$html,$templateKey,$templateVersion
    );
    if ($capture !== null) {
        brvtalAdminMailLogResult($templateKey,$templateVersion,$capture);
        return $capture;
    }
    $host = trim((string)brvtal_secret_value('BRVTAL_SMTP_HOST'));
    $user = trim((string)brvtal_secret_value('BRVTAL_SMTP_USER'));
    $password = (string)brvtal_secret_value('BRVTAL_SMTP_PASSWORD');
    $from = trim((string)brvtal_secret_value('BRVTAL_MAIL_FROM'));
    $port = (int)(brvtal_secret_value('BRVTAL_SMTP_PORT') ?: 587);
    $encryption = strtolower(trim((string)(brvtal_secret_value('BRVTAL_SMTP_ENCRYPTION') ?: 'tls')));
    if ($host === '' || $user === '' || $password === '' || $from === ''
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || !filter_var($from, FILTER_VALIDATE_EMAIL)
        || !in_array($encryption, ['tls', 'ssl'], true)
        || $port < 1 || $port > 65535) {
        return false;
    }
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        if (function_exists('brvtal_log')) {
            brvtal_log('MAIL_DELIVERY_FAILED', 'Transactional mail runtime is unavailable', [
                'purpose' => $purpose,
                'reason' => 'autoload_missing',
            ]);
        }
        return false;
    }
    require_once $autoload;
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = $port;
        $mail->SMTPAuth = true;
        $mail->Username = $user;
        $mail->Password = $password;
        $mail->SMTPSecure = $encryption === 'ssl'
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAutoTLS = true;
        $mail->Timeout = 2;
        $mail->Timelimit = 2;
        $mail->SMTPDebug = 0;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($from, trim((string)(brvtal_secret_value('BRVTAL_MAIL_FROM_NAME') ?: 'BRVTAL')));
        $mail->addAddress($email);
        $mail->isHTML($html !== null);
        $mail->Subject = $subject;
        $mail->Body = $html ?? $body;
        if ($html !== null) {
            $mail->AltBody = $body;
        }
        $sent = $mail->send();
        brvtalAdminMailLogResult($templateKey,$templateVersion,$sent);
        return $sent;
    } catch (Throwable $e) {
        brvtalAdminMailLogResult($templateKey,$templateVersion,false);
        if (function_exists('brvtal_log')) {
            brvtal_log('MAIL_DELIVERY_FAILED', 'Transactional mail delivery failed', [
                'purpose' => $purpose,
                'class' => $e::class,
            ]);
        }
        return false;
    }
}
