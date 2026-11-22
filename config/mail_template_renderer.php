<?php
declare(strict_types=1);
require_once __DIR__ . '/mail_template_catalog.php';

/** Snapshot of an allowed public image; never trust arbitrary URLs from templates. */
function brvtal_mail_delivery_media(PDO $pdo, ?int $id): ?string
{
    if ($id === null || $id < 1) return null;
    try {
        $query = $pdo->prepare("SELECT file_path FROM media
            WHERE id=? AND status='published' AND type='image' LIMIT 1");
        $query->execute([$id]);
        $path = $query->fetchColumn();
    } catch (PDOException) {
        return null;
    }
    if (!is_string($path) || !str_starts_with($path, '/uploads/')
        || str_contains($path, '..') || str_contains($path, '//')
        || preg_match('~[\x00-\x20?#<>"\\]~', $path)) return null;
    return 'https://www.brvtal.com.co' . $path;
}

/** Resolve the published revision in one read, before queueing; never render in a worker. */
function brvtal_mail_delivery_document(PDO $pdo, string $kind): array
{
    $defaults = brvtal_mail_template_definition($kind, 'ticketing')['defaults'];
    try {
        $query = $pdo->prepare("SELECT v.id,v.document_json FROM mail_template_registry r
            JOIN mail_template_versions v ON v.id=r.published_version_id
                AND v.template_id=r.id AND v.first_published_at IS NOT NULL
                AND v.discarded_at IS NULL
            WHERE r.template_key=? AND r.scope='ticketing' LIMIT 1");
        $query->execute([$kind]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $error) {
        if (in_array((string)$error->getCode(), ['42S02','42S22'], true)) {
            return ['source'=>'catalog','document'=>$defaults,'version'=>null];
        }
        throw $error;
    }
    if (!is_array($row)) return ['source'=>'catalog','document'=>$defaults,'version'=>null];
    try {
        $decoded = json_decode((string)$row['document_json'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new RuntimeException('MAIL_TEMPLATE_DOCUMENT_CORRUPTED');
        $valid = brvtal_mail_template_validate($kind, 'ticketing', $decoded,
            static fn(int $id): bool => brvtal_mail_delivery_media($pdo, $id) !== null);
        return ['source'=>'published','document'=>$valid,'version'=>(int)$row['id']];
    } catch (Throwable) {
        // Published corrupt/revoked assets: fail closed, never send an attacker-edited HTML.
        throw new RuntimeException('MAIL_TEMPLATE_DOCUMENT_CORRUPTED');
    }
}

/** Server-only variables and CTA; the published document cannot provide its own URL. */
function brvtal_mail_delivery_render(PDO $pdo, string $kind, array $data): array
{
    $definition = brvtal_mail_template_definition($kind, 'ticketing');
    $event = trim((string)($data['event'] ?? ''));
    if ($event !== '' && (strlen($event) > 160 || preg_match('/[\r\n\x00-\x1f]/', $event))) {
        throw new InvalidArgumentException('MAIL_EVENT_INVALID');
    }
    if ($event === '' && !in_array($kind, ['verify_account','reset_password'], true)) {
        throw new InvalidArgumentException('MAIL_EVENT_INVALID');
    }
    $link = (string)($data['link'] ?? '');
    $parts = parse_url($link);
    $host = is_array($parts) ? strtolower((string)($parts['host'] ?? '')) : '';
    $fixture = getenv('BRVTAL_TICKET_MAIL_TESTING') === '1' && $host === 'brvtal.invalid';
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
        || (!$fixture && !in_array($host, ['www.brvtal.com.co','brvtal.com.co'], true))
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
        || isset($parts['fragment']) || strpbrk($link,"\r\n") !== false || strlen($link) > 2048) {
        throw new InvalidArgumentException('MAIL_LINK_INVALID');
    }
    if (in_array($kind, ['payment_approved','reminder'], true)
        && (isset($parts['query']) || !str_starts_with((string)($parts['path'] ?? ''), '/buyer/'))) {
        throw new InvalidArgumentException('MAIL_LINK_NOT_PROTECTED');
    }
    if (in_array($kind, ['verify_account','reset_password'], true)) {
        $expectedAction = $kind === 'verify_account' ? 'verify' : 'confirm-reset';
        parse_str((string)($parts['query'] ?? ''), $params);
        if ($fixture === false && (string)($parts['path'] ?? '') !== '/mi-cuenta/restablecer') {
            throw new InvalidArgumentException('MAIL_LINK_NOT_PROTECTED');
        }
        if (count($params) !== 2 || ($params['accion'] ?? null) !== $expectedAction
            || !is_string($params['token'] ?? null)
            || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $params['token'])) {
            throw new InvalidArgumentException('MAIL_LINK_NOT_PROTECTED');
        }
    }
    $resolved = brvtal_mail_delivery_document($pdo,$kind);
    $copy = $resolved['document'];
    $replace = static fn(string $value): string => str_replace('{{event_name}}',$event,$value);
    $subject=$replace((string)$copy['subject']);
    $title=$replace((string)$copy['title']);
    $message=$replace((string)$copy['message']);
    $cta=$replace((string)$copy['cta']);
    $footer=$replace((string)$copy['footer']);
    $preheader=$replace((string)$copy['preheader']);
    $theme=$copy['theme'];
    $escape=static fn(string $value): string => htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $logo=brvtal_mail_delivery_media($pdo, $theme['logo_media_id']);
    $hero=brvtal_mail_delivery_media($pdo, $theme['hero_media_id']);
    $logoMarkup=$logo===null ? '<strong style="letter-spacing:.12em">BRVTAL</strong>'
        : '<img alt="BRVTAL" width="145" src="'.$escape($logo).'" style="max-width:145px;height:auto">';
    $heroMarkup=$hero===null ? '' : '<p><img alt="" src="'.$escape($hero).'"
        style="max-width:100%;height:auto" width="560"></p>';
    $eventText=$event!=='' ? "Evento: ".$event."\n\n" : '';
    $text=$title."\n\n".$eventText.$message."\n\n".$cta.': '.$link."\n\n".$footer;
    $html='<!doctype html><html lang="es"><head><meta charset="utf-8">'
        .'<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        .'<body style="margin:0;padding:0;background:'.$theme['background'].';color:'.$theme['foreground'].';font-family:Arial,sans-serif">'
        .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
        .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px">'
        .'<tr><td style="padding:28px 20px;line-height:1.5">'
        .'<div style="display:none!important;max-height:0;overflow:hidden;opacity:0">'.$escape($preheader).'</div>'
        .$logoMarkup.$heroMarkup
        .'<h1 style="font-size:26px;line-height:1.3">'.$escape($title).'</h1>'
        .($event!==''?'<p><strong>Evento:</strong> '.$escape($event).'</p>':'')
        .'<p style="white-space:pre-line;line-height:1.6">'.nl2br($escape($message),false).'</p>'
        .'<p style="padding:18px 0"><a href="'.$escape($link).'"'
        .' style="display:inline-block;padding:14px 18px;background:'.$theme['accent']
        .';color:'.$theme['background'].';text-decoration:underline;font-weight:bold">'
        .$escape($cta).'</a></p>'
        .'<p style="font-size:13px;line-height:1.5">'.$escape($footer).'</p>'
        .'</td></tr></table></td></tr></table></body></html>';
    if ($subject==='' || strlen($subject)>255 || strlen($text)>50000 || strlen($html)>150000) {
        throw new RuntimeException('MAIL_TEMPLATE_RENDER_INVALID');
    }
    return ['subject'=>$subject,'text'=>$text,'html'=>$html,'template'=>$kind,
        'template_key'=>$kind,'template_version'=>$resolved['version'],
        'template_source'=>$resolved['source']];
}
