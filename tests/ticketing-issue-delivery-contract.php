<?php
declare(strict_types=1);

/** #1945: QR delivery contract (no database). */
require_once __DIR__ . '/../config/mail_template_renderer.php';

function deliveryContract(bool $ok, string $what): void
{
    if (!$ok) throw new RuntimeException('ISSUE_DELIVERY_CONTRACT: ' . $what);
}
function deliveryContractRejects(callable $fn, string $what): void
{
    try {
        $fn();
    } catch (InvalidArgumentException $error) {
        deliveryContract($error->getMessage() === 'MAIL_TICKETS_INVALID', $what);
        return;
    }
    deliveryContract(false, $what . ' was accepted');
}

$root = dirname(__DIR__);
$id = str_repeat('a', 32);
$token = str_repeat('B', 43);
$good = 'https://www.brvtal.com.co/mi-cuenta/boleta#BRVTAL:T1:' . $id . ':' . $token;
deliveryContract(brvtal_mail_delivery_tickets('payment_approved', null, false) === [], 'no tickets is allowed');
deliveryContract(brvtal_mail_delivery_tickets('payment_approved', [['label'=>'Boleta 1', 'url'=>$good]], false)
    === [['label'=>'Boleta 1', 'url'=>$good]], 'a production ticket link is accepted');
deliveryContractRejects(fn() => brvtal_mail_delivery_tickets('reminder', [['label'=>'B', 'url'=>$good]], false),
    'tickets only travel in the paid-order message');
foreach ([
    'https://evil.example/mi-cuenta/boleta#BRVTAL:T1:' . $id . ':' . $token,
    'https://www.brvtal.com.co/mi-cuenta/boleta?t=BRVTAL:T1:' . $id . ':' . $token,
    'http://www.brvtal.com.co/mi-cuenta/boleta#BRVTAL:T1:' . $id . ':' . $token,
    'https://www.brvtal.com.co/mi-cuenta/boleta#BRVTAL:T1:' . $id . ':' . $token . '"><script>',
    'https://brvtal.invalid/mi-cuenta/boleta#BRVTAL:T1:' . $id . ':' . $token,
] as $bad) {
    deliveryContractRejects(fn() => brvtal_mail_delivery_tickets('payment_approved', [['label'=>'B', 'url'=>$bad]], false),
        'reject ' . $bad);
}
deliveryContractRejects(fn() => brvtal_mail_delivery_tickets('payment_approved', [['label'=>"B\nBcc: x", 'url'=>$good]], false),
    'labels cannot inject headers or lines');
deliveryContractRejects(fn() => brvtal_mail_delivery_tickets('payment_approved', [], false), 'empty ticket list');

// The QR travels sealed: queue_order seals both bodies whenever tickets are present.
$queue = (string)file_get_contents($root . '/config/ticketing_mail_templates.php');
deliveryContract(preg_match('/isset\(\$data\[\'tickets\'\]\).*?brvtalBuyerMailSeal\(\$text.*?brvtalBuyerMailSeal\(\$html/s', $queue) === 1,
    'paid-order email bodies with tickets are sealed');

// Issuance happens before mail delivery in the existing cron worker.
$worker = (string)file_get_contents($root . '/ops/ticketing-mail-worker.php');
$issue = strpos($worker, 'brvtal_ticket_issue_process_next(');
$mail = strpos($worker, 'brvtal_ticket_mail_deliver_one(');
deliveryContract($issue !== false && $mail !== false && $issue < $mail, 'worker issues before delivering mail');

// Delivery runs inside the issuance transaction, before commit.
$consume = (string)file_get_contents($root . '/config/ticketing_issue.php');
deliveryContract(preg_match('/\$deliver\(\$db, \(int\)\$orderId, \$tickets\);\s*\}\s*\$db->commit\(\);/', $consume) === 1,
    'deliverer runs before the issuance commit');

// The public ticket page never sends the fragment anywhere.
$view = (string)file_get_contents($root . '/js/public-ticket-view.js');
foreach (['fetch(', 'XMLHttpRequest', 'sendBeacon', 'localStorage', 'sessionStorage', 'gtag', 'dataLayer'] as $needle) {
    deliveryContract(!str_contains($view, $needle), 'ticket view does not use ' . $needle);
}
$page = (string)file_get_contents($root . '/buyer/ticket.php');
deliveryContract(!preg_match('~<script[^>]+src="https?://~', $page), 'no third-party scripts on the ticket page');
deliveryContract(str_contains($page, 'no-referrer') && str_contains($page, 'no-store'), 'no referrer and no cache');
$htaccess = (string)file_get_contents($root . '/.htaccess');
deliveryContract(str_contains($htaccess, 'RewriteRule ^mi-cuenta/boleta/?$ buyer/ticket.php'), 'route /mi-cuenta/boleta');

echo "BRVTAL #1945 QR delivery contract passed.\n";
