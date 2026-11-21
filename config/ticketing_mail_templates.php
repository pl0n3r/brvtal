<?php
declare(strict_types=1);
require_once __DIR__ . '/ticketing_mail_outbox.php';
require_once __DIR__ . '/ticketing_mail_events.php';

/** T12: one Spanish, escaped, accessible mail copy per trusted transaction. */
function brvtal_ticket_mail_render(string $kind, array $data): array
{
    $catalog = require __DIR__ . '/../templates/ticketing/transactional.php';
    if (!isset($catalog[$kind])) throw new InvalidArgumentException('MAIL_TEMPLATE_UNKNOWN');
    $event = trim((string)($data['event'] ?? ''));
    if (!in_array($kind, ['verify_account','reset_password'], true)
        && ($event === '' || strlen($event) > 160 || preg_match('/[\r\n]/', $event))) {
        throw new InvalidArgumentException('MAIL_EVENT_INVALID');
    }
    $link = (string)($data['link'] ?? '');
    $parts = parse_url($link);
    $host = is_array($parts) ? strtolower((string)($parts['host'] ?? '')) : '';
    $testHost = getenv('BRVTAL_TICKET_MAIL_TESTING') === '1' && $host === 'brvtal.invalid';
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
        || (!$testHost && !in_array($host, ['brvtal.com.co','www.brvtal.com.co'], true))
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
        || isset($parts['fragment']) || strpbrk($link, "\r\n") !== false
        || strlen($link) > 2048) {
        throw new InvalidArgumentException('MAIL_LINK_INVALID');
    }
    if (in_array($kind, ['payment_approved','reminder'], true)
        && (isset($parts['query']) || !str_starts_with((string)($parts['path'] ?? ''), '/buyer/'))) {
        throw new InvalidArgumentException('MAIL_LINK_NOT_PROTECTED');
    }
    $template = $catalog[$kind];
    $subject = $template['subject'];
    $body = $template['message'];
    $detail = $event !== '' ? "Evento: {$event}\n\n" : '';
    $text = "{$template['title']}\n\n{$detail}{$body}\n\n{$template['cta']}: {$link}\n\nBRVTAL · Mensaje transaccional, no publicitario.";
    $escape = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $detailHtml = $event !== '' ? '<p><strong>Evento:</strong> ' . $escape($event) . '</p>' : '';
    $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '</head><body style="margin:0;background:#111;color:#f7f7f7;font-family:Arial,sans-serif">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:620px;margin:auto">'
        . '<tr><td style="padding:32px 20px"><p style="font-weight:bold;letter-spacing:.15em">BRVTAL</p>'
        . '<h1 style="font-size:26px;line-height:1.3">' . $escape($template['title']) . '</h1>'
        . $detailHtml . '<p style="line-height:1.6">' . $escape($body) . '</p>'
        . '<p style="padding:20px 0"><a href="' . $escape($link)
        . '" style="display:inline-block;padding:14px 20px;background:#fff;color:#111;text-decoration:underline;font-weight:bold">'
        . $escape($template['cta']) . '</a></p>'
        . '<p style="font-size:13px;line-height:1.5">Mensaje transaccional, no publicitario. Si no reconoces esta actividad, ignora el mensaje.</p>'
        . '</td></tr></table></body></html>';
    return ['subject'=>$subject, 'text'=>$text, 'html'=>$html, 'template'=>$kind];
}

/**
 * Insert in the caller's transaction; T10 worker sees the job only after commit.
 * No delivery, QR generation, payment or database commit occurs here.
 */
function brvtal_ticket_mail_queue_order(
    PDO $db, int $orderId, string $kind, string $recipient, array $data
): int {
    $states = ['order_pending'=>['pending'], 'order_rejected'=>['failed','cancelled'],
        'payment_approved'=>['paid'], 'reminder'=>['paid'], 'refund'=>['refunded']];
    if (!isset($states[$kind]) || $orderId < 1) {
        throw new InvalidArgumentException('MAIL_ORDER_TEMPLATE_INVALID');
    }
    if (!$db->inTransaction()) throw new RuntimeException('MAIL_TRANSACTION_REQUIRED');
    $query = $db->prepare('SELECT o.id,o.event_id,o.buyer_id,o.status,b.email
        FROM ticket_orders o JOIN ticket_buyers b ON b.id=o.buyer_id
        WHERE o.id=? FOR UPDATE');
    $query->execute([$orderId]);
    $order = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($order) || !in_array($order['status'], $states[$kind], true)
        || !is_string($order['email']) || strtolower($recipient) !== strtolower($order['email'])) {
        throw new DomainException('MAIL_ORDER_UNAVAILABLE');
    }
    if (in_array($kind, ['payment_approved','reminder'], true)) {
        // Signed provider journal + a completed T09 issuance, not a browser return.
        $proof = $db->prepare("SELECT 1 FROM ticket_payment_attempts a
            JOIN ticket_payment_webhook_events w ON w.attempt_id=a.id
              AND w.provider_transaction_id=a.provider_transaction_id
            JOIN ticket_issue_outbox x ON x.order_id=a.order_id AND x.payment_attempt_id=a.id
            WHERE a.order_id=? AND a.provider='wompi' AND a.status='approved'
              AND a.provider_transaction_id IS NOT NULL AND x.state='done'
              AND w.signed_status='APPROVED' LIMIT 1");
        $proof->execute([$orderId]);
        if ($proof->fetchColumn() === false) throw new DomainException('MAIL_PAYMENT_NOT_VERIFIED');
        $count = $db->prepare("SELECT COUNT(e.id) AS issued
            FROM ticket_order_items i LEFT JOIN ticket_entries e ON e.order_item_id=i.id
              AND e.status='issued' WHERE i.order_id=?");
        $count->execute([$orderId]);
        $counts = $count->fetch(PDO::FETCH_ASSOC);
        $expected = $db->prepare('SELECT COALESCE(SUM(quantity),0) FROM ticket_order_items WHERE order_id=?');
        $expected->execute([$orderId]);
        $units = (int)$expected->fetchColumn();
        if ($units < 1 || $units > 400 || (int)($counts['issued'] ?? 0) !== $units) {
            throw new DomainException('MAIL_TICKETS_NOT_READY');
        }
    }
    $rendered = brvtal_ticket_mail_render($kind, $data);
    $id = brvtal_ticket_mail_enqueue($db, "order:{$orderId}:{$kind}", "order:{$orderId}",
        $recipient, $rendered['subject'], $rendered['text']);
    brvtal_mail_history_record($db, $id, ['event_id'=>(int)$order['event_id'],
        'buyer_id'=>(int)$order['buyer_id'],'order_id'=>$orderId,'template_key'=>$kind]);
    return $id;
}

/** Enqueue only a previously minted, unconsumed, unrevoked one-use buyer token. */
function brvtal_ticket_mail_queue_security(
    PDO $db, int $buyerId, int $tokenId, string $kind, string $recipient, array $data
): int {
    $purpose = ['verify_account'=>'verify_email','reset_password'=>'reset_password'][$kind] ?? null;
    if ($purpose === null || $buyerId < 1 || $tokenId < 1) {
        throw new InvalidArgumentException('MAIL_TOKEN_TEMPLATE_INVALID');
    }
    if (!$db->inTransaction()) throw new RuntimeException('MAIL_TRANSACTION_REQUIRED');
    $token = (string)($data['token'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)
        || !str_contains((string)($data['link'] ?? ''), 'token=' . $token)) {
        throw new DomainException('MAIL_TOKEN_INVALID');
    }
    $get = $db->prepare('SELECT t.token_hash,b.email FROM ticket_buyer_tokens t
        JOIN ticket_buyers b ON b.id=t.buyer_id
        WHERE t.id=? AND t.buyer_id=? AND t.purpose=?
          AND t.consumed_at_utc IS NULL AND t.revoked_at_utc IS NULL
          AND t.expires_at_utc>UTC_TIMESTAMP(6) FOR UPDATE');
    $get->execute([$tokenId,$buyerId,$purpose]);
    $row = $get->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || !is_string($row['email'])
        || strtolower($row['email']) !== strtolower($recipient)
        || !hash_equals((string)$row['token_hash'], hash('sha256', $token))) {
        throw new DomainException('MAIL_TOKEN_UNAVAILABLE');
    }
    $rendered = brvtal_ticket_mail_render($kind, $data);
    $id = brvtal_ticket_mail_enqueue($db, "buyer:{$buyerId}:{$kind}:{$tokenId}",
        "buyer:{$buyerId}", $recipient, $rendered['subject'], $rendered['text']);
    brvtal_mail_history_record($db,$id,['buyer_id'=>$buyerId,'template_key'=>$kind]);
    return $id;
}
