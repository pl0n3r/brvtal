<?php
declare(strict_types=1);

/**
 * Buyer-security messages are sealed in the existing transactional outbox.
 * The private Hostinger encryption_key never enters the DB or Git.
 */
const BRVTAL_BUYER_MAIL_SEAL_PREFIX = 'BRVTAL_BUYER_SEALED_V1:';

function brvtalBuyerMailCipherKey(): string
{
    $config = $GLOBALS['config'] ?? [];
    $key = is_array($config) && is_array($config['security'] ?? null)
        ? ($config['security']['encryption_key'] ?? null) : null;
    if (!is_string($key) || strlen($key) < 32 || str_starts_with($key, 'REPLACE_')) {
        throw new RuntimeException('BUYER_MAIL_KEY_UNAVAILABLE');
    }
    return hash('sha256', 'brvtal-buyer-mail-v1:' . $key, true);
}

function brvtalBuyerMailSeal(string $plaintext, int $maxLength = 12000): string
{
    if ($plaintext === '' || $maxLength > 50000 || strlen($plaintext) > $maxLength) {
        throw new InvalidArgumentException('BUYER_MAIL_INVALID_BODY');
    }
    $nonce = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', brvtalBuyerMailCipherKey(),
        OPENSSL_RAW_DATA, $nonce, $tag, 'brvtal-buyer-mail:v1', 16);
    if (!is_string($cipher) || strlen($tag) !== 16) {
        throw new RuntimeException('BUYER_MAIL_SEAL_FAILED');
    }
    return BRVTAL_BUYER_MAIL_SEAL_PREFIX . base64_encode($nonce . $tag . $cipher);
}

/** A failed authentication never emits a partial plaintext message. */
function brvtalBuyerMailUnseal(string $stored): ?string
{
    if (!str_starts_with($stored, BRVTAL_BUYER_MAIL_SEAL_PREFIX)) {
        return $stored;
    }
    $payload = base64_decode(substr($stored, strlen(BRVTAL_BUYER_MAIL_SEAL_PREFIX)), true);
    if (!is_string($payload) || strlen($payload) <= 28) {
        return null;
    }
    try {
        $plain = openssl_decrypt(substr($payload, 28), 'aes-256-gcm',
            brvtalBuyerMailCipherKey(), OPENSSL_RAW_DATA,
            substr($payload, 0, 12), substr($payload, 12, 16), 'brvtal-buyer-mail:v1');
    } catch (Throwable) {
        return null;
    }
    return is_string($plain) && $plain !== '' ? $plain : null;
}

/** Only the message transport may open this memory-only link. */
function brvtalBuyerMailContent(string $kind, string $token): array
{
    if (!in_array($kind, ['verify_account', 'reset_password'], true)
        || preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
        throw new InvalidArgumentException('BUYER_MAIL_INVALID_TOKEN');
    }
    $catalog = require __DIR__ . '/../templates/ticketing/transactional.php';
    $copy = $catalog[$kind];
    $action = $kind === 'verify_account' ? 'verify' : 'confirm-reset';
    $url = 'https://www.brvtal.com.co/mi-cuenta/restablecer?accion=' . $action
        . '&token=' . rawurlencode($token);
    $text = $copy['title'] . "\n\n" . $copy['message'] . "\n\n"
        . $copy['cta'] . ': ' . $url
        . "\n\nBRVTAL · Correo transaccional, no publicitario.";
    return ['subject' => $copy['subject'], 'sealed' => brvtalBuyerMailSeal($text)];
}


/** Extract only canonical one-use links from a sealed buyer message, in memory. */
function brvtalBuyerMailExtractLink(string $plaintext, string $kind): ?string
{
    $action = ['verify_account'=>'verify','reset_password'=>'confirm-reset'][$kind] ?? null;
    if ($action === null) return null;
    $pattern='~https://www\.brvtal\.com\.co/mi-cuenta/restablecer\?accion='
        .preg_quote($action,'~').'&token=[A-Za-z0-9_-]{43}(?![A-Za-z0-9_-])~D';
    if (preg_match($pattern, $plaintext, $match) !== 1) return null;
    return $match[0];
}


/** Purchase-only CTA. Uses the same 30-minute, one-use password reset token. */
function brvtalBuyerMailGuestPasswordLink(string $token): string
{
    if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
        throw new InvalidArgumentException('BUYER_MAIL_INVALID_TOKEN');
    }
    return 'https://www.brvtal.com.co/mi-cuenta/restablecer?accion=confirm-reset&token='
        . rawurlencode($token) . '&origen=compra';
}
