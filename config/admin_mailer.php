<?php
declare(strict_types=1);

function brvtalAdminMailCapture(string $email, string $subject, string $body, string $purpose): ?bool
{
    $transport = $GLOBALS['brvtal_password_mail_transport'] ?? null;
    if (is_callable($transport)) {
        return (bool)$transport($email, $subject, $body);
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
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($record)
        && file_put_contents($path, $record . "\n", FILE_APPEND | LOCK_EX) !== false;
}

function brvtalAdminMailSend(
    string $email,
    string $subject,
    string $body,
    string $purpose = 'security_notification'
): bool {
    $capture = brvtalAdminMailCapture($email, $subject, $body, $purpose);
    if ($capture !== null) {
        return $capture;
    }
    $host = trim((string)getenv('BRVTAL_SMTP_HOST'));
    $user = trim((string)getenv('BRVTAL_SMTP_USER'));
    $password = (string)getenv('BRVTAL_SMTP_PASSWORD');
    $from = trim((string)getenv('BRVTAL_MAIL_FROM'));
    $port = (int)(getenv('BRVTAL_SMTP_PORT') ?: 587);
    $encryption = strtolower(trim((string)(getenv('BRVTAL_SMTP_ENCRYPTION') ?: 'tls')));
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
        $mail->setFrom($from, trim((string)(getenv('BRVTAL_MAIL_FROM_NAME') ?: 'BRVTAL')));
        $mail->addAddress($email);
        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body = $body;
        return $mail->send();
    } catch (Throwable $e) {
        if (function_exists('brvtal_log')) {
            brvtal_log('MAIL_DELIVERY_FAILED', 'Transactional mail delivery failed', [
                'purpose' => $purpose,
                'class' => $e::class,
            ]);
        }
        return false;
    }
}
