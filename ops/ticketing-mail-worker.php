#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// This CLI-only script can be required by a synthetic MariaDB integration test.
// Merely loading its pure cycle contract never reads credentials or starts cron.
function brvtal_ticket_mail_worker_gate(callable $secret): array
{
    $mode = trim((string)$secret('BRVTAL_TICKET_MAIL_MODE'));
    if ($mode === '' || $mode === 'disabled' || $mode === 'off') {
        return ['mail' => 'disabled', 'issue' => 'disabled'];
    }
    if (!in_array($mode, ['live', 'test', 'mock'], true)) {
        return ['mail' => 'not_ready', 'issue' => 'not_ready'];
    }
    if ($mode === 'mock') {
        $ready = $secret('BRVTAL_TICKET_MAIL_TESTING') === '1';
        return ['mail' => $ready ? 'ready' : 'not_ready',
            'issue' => $ready ? 'ready' : 'not_ready'];
    }
    foreach (['BRVTAL_TICKET_MAIL_API_KEY', 'BRVTAL_TICKET_MAIL_DOMAIN',
        'BRVTAL_TICKET_MAIL_FROM'] as $key) {
        if (trim((string)$secret($key)) === '') {
            return ['mail' => 'not_ready', 'issue' => 'not_ready'];
        }
    }
    // Match Resend's sender-domain gate before minting a QR which would require
    // protected mail to be enqueued. The adapter rechecks every recipient/job.
    $domain = strtolower(trim((string)$secret('BRVTAL_TICKET_MAIL_DOMAIN')));
    $from = trim((string)$secret('BRVTAL_TICKET_MAIL_FROM'));
    $fromEmail = preg_match('/<([^<>]+)>/', $from, $parts) ? $parts[1] : $from;
    if (!filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
        || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)
        || strtolower((string)substr(strrchr($fromEmail, '@') ?: '', 1)) !== $domain
        || strpbrk($from, "\r\n") !== false) {
        return ['mail' => 'not_ready', 'issue' => 'not_ready'];
    }
    if ($mode === 'test') {
        // Even a test transport needs its allowlist; test mode never issues QR.
        $ready = trim((string)$secret('BRVTAL_TICKET_MAIL_TEST_RECIPIENTS')) !== '';
        return ['mail' => $ready ? 'ready' : 'not_ready', 'issue' => 'not_ready'];
    }
    // The delivery adapter enforces these gates too; do not issue QR while
    // delivery is disabled or while recipient/privacy approval is missing.
    $live = $secret('BRVTAL_TICKET_MAIL_ALLOW_LIVE') === '1'
        && $secret('BRVTAL_TICKET_MAIL_PRIVACY_APPROVED') === '1'
        && $secret('BRVTAL_TICKET_MAIL_DNS_VERIFIED') === '1';
    return ['mail' => $live ? 'ready' : 'not_ready',
        'issue' => $live ? 'ready' : 'not_ready'];
}

/** No stage may reflect arbitrary exception text, PII, or a provider payload. */
function brvtal_ticket_mail_worker_state(string $stage, mixed $value): string
{
    if (!is_string($value)) return 'unavailable';
    $allowed = match ($stage) {
        'reconcile' => ['idle','skipped','not_found','not_approved','approved',
            'approved_unfulfilled','duplicate','unavailable'],
        'issue' => ['idle','issued','duplicate','waiting','unavailable'],
        'state' => ['idle','accepted','retry','failed','uncertain','lease_lost','unavailable'],
        default => [],
    };
    if ($stage === 'issue' && preg_match('/^failed:[A-Z][A-Z0-9_]{2,63}$/D', $value)) {
        return $value;
    }
    // A disabled reconciliation must be distinguishable from truly idle work.
    if ($stage === 'reconcile' && $value === 'skipped') return 'disabled';
    return in_array($value, $allowed, true) ? $value : 'unavailable';
}

/**
 * One cycle: preflight globally, then reconcile independently of mail config;
 * issuance must never mint a QR which cannot be queued for mail delivery.
 * Callbacks allow a synthetic test to verify execution and bounds without
 * contacting Wompi or Resend. Every active stage runs at most once.
 *
 * @return array{output:array<string,string>,exit_code:int}
 */
function brvtal_ticket_mail_worker_cycle(
    callable $preflight, callable $reconcile, callable $issue, callable $mail
): array {
    $output = ['reconcile' => 'not_run', 'issue' => 'not_run', 'state' => 'not_run'];
    try {
        $context = $preflight();
        if (!is_array($context) || !($context['pdo'] ?? null) instanceof PDO
            || !is_array($context['gate'] ?? null)
            || !in_array($context['gate']['mail'] ?? null, ['ready','disabled','not_ready'], true)
            || !in_array($context['gate']['issue'] ?? null, ['ready','disabled','not_ready'], true)) {
            throw new RuntimeException('WORKER_PREFLIGHT_INVALID');
        }
        $pdo = $context['pdo'];
        $gate = $context['gate'];
    } catch (Throwable) {
        return ['output' => $output, 'exit_code' => 2];
    }
    try {
        $output['reconcile'] = brvtal_ticket_mail_worker_state('reconcile', $reconcile($pdo));
    } catch (Throwable) {
        $output['reconcile'] = 'unavailable';
    }
    foreach (['issue' => $issue, 'state' => $mail] as $name => $step) {
        $authorization = $name === 'issue' ? $gate['issue'] : $gate['mail'];
        if ($authorization !== 'ready') {
            $output[$name] = $authorization;
            continue;
        }
        try {
            $output[$name] = brvtal_ticket_mail_worker_state($name, $step($pdo));
        } catch (Throwable) {
            $output[$name] = 'unavailable';
        }
    }
    $ok = in_array($output['reconcile'], ['idle','approved','duplicate'], true)
        && in_array($output['issue'], ['idle','issued','duplicate'], true)
        && in_array($output['state'], ['idle','accepted'], true);
    return ['output' => $output, 'exit_code' => $ok ? 0 : 1];
}

// Tests include only these contracts: they cannot trigger global config, DB,
// mail provider calls or a cron run simply by requiring this file.
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) return;

$root = dirname(__DIR__);
$preflight = static function () use ($root): array {
    $configPath = $root . '/config/config.php';
    if (!is_file($configPath)) throw new RuntimeException('WORKER_CONFIG_UNAVAILABLE');
    $config = require $configPath;
    if (!is_array($config) || !is_array($config['db'] ?? null)) {
        throw new RuntimeException('WORKER_CONFIG_INVALID');
    }
    $GLOBALS['config'] = $config;
    require_once $root . '/config/integration_secrets_store.php';
    // This master key protects encrypted integration keys across every stage.
    // Its absence remains a global fail-closed veto, before opening a DB.
    if (brvtalIntegrationSecretMasterKey($config) === null) {
        throw new RuntimeException('WORKER_MASTER_KEY_UNAVAILABLE');
    }
    $d = $config['db'];
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $d['host'] ?? '127.0.0.1', (int)($d['port'] ?? 3306),
            $d['name'] ?? '', $d['charset'] ?? 'utf8mb4'),
        (string)($d['user'] ?? ''), (string)($d['pass'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false]
    );
    $GLOBALS['brvtal_mail_worker_pdo'] = $pdo;
    // The standalone worker owns the DB handle for secrets in encrypted stores.
    if (!function_exists('db')) {
        function db(): PDO {
            $pdo = $GLOBALS['brvtal_mail_worker_pdo'] ?? null;
            if (!$pdo instanceof PDO) throw new RuntimeException('MAIL_WORKER_DB_UNAVAILABLE');
            return $pdo;
        }
    }
    require_once $root . '/config/secrets.php';
    $secret = static fn(string $key): string => brvtal_secret_value($key, null, $config, $pdo);
    $gate = brvtal_ticket_mail_worker_gate($secret);
    return compact('pdo', 'gate');
};
$reconcile = static function (PDO $pdo) use ($root): string {
    // The verified-payment path may enqueue mail, but never contacts Resend.
    // Preserve the original prerequisite load order for reconciliation.
    require_once $root . '/config/ticketing_mail_outbox.php';
    require_once $root . '/config/ticketing_wompi_reconcile.php';
    return brvtal_wompi_reconcile_next($pdo);
};
$issue = static function (PDO $pdo) use ($root): string {
    require_once $root . '/config/ticketing_issue_delivery.php';
    return brvtal_ticket_issue_process_next($pdo);
};
$mail = static function (PDO $pdo) use ($root): string {
    require_once $root . '/config/ticketing_mail_outbox.php';
    return brvtal_ticket_mail_deliver_one($pdo);
};
$result = brvtal_ticket_mail_worker_cycle($preflight, $reconcile, $issue, $mail);
$output = $result['output'];
if ($result['exit_code'] === 2) {
    // No stage ran: preserve a neutral error without leaking config/secrets.
    fwrite(STDERR, "ticket_mail_worker_preflight_unavailable\n");
}
// #1956: a known, sanitized failure code may be reported only when issuance ran.
if ($output['issue'] !== 'not_run' && function_exists('brvtal_ticket_issue_last_failure')) {
    $last = brvtal_ticket_issue_last_failure();
    if (is_array($last) && preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', (string)($last['code'] ?? ''))
        && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', (string)($last['at'] ?? ''))) {
        $output['issue_last'] = $last['code'] . '@' . $last['at'];
    }
}
fwrite(STDOUT, json_encode($output, JSON_THROW_ON_ERROR) . "\n");
exit($result['exit_code']);
