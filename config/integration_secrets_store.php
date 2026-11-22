<?php
declare(strict_types=1);

/**
 * Encrypted at-rest integration credentials. The database never holds the key.
 * No provider mode or sending permission is changed by this module.
 */
function brvtalIntegrationSecretCatalog(): array
{
    return [
        'BRVTAL_TICKET_MAIL_API_KEY' => ['provider'=>'resend','field'=>'api_key'],
        'BRVTAL_TICKET_MAIL_DOMAIN' => ['provider'=>'resend','field'=>'domain'],
        'BRVTAL_TICKET_MAIL_FROM' => ['provider'=>'resend','field'=>'from'],
        'BRVTAL_TICKET_MAIL_WEBHOOK_SECRET' => ['provider'=>'resend','field'=>'webhook_secret'],
        'BRVTAL_SMTP_HOST' => ['provider'=>'smtp','field'=>'host'],
        'BRVTAL_SMTP_USER' => ['provider'=>'smtp','field'=>'user'],
        'BRVTAL_SMTP_PASSWORD' => ['provider'=>'smtp','field'=>'password'],
        'BRVTAL_MAIL_FROM' => ['provider'=>'smtp','field'=>'from'],
        'BRVTAL_GA4_PROPERTY_ID' => ['provider'=>'ga4','field'=>'property_id'],
        'BRVTAL_GA4_CLIENT_EMAIL' => ['provider'=>'ga4','field'=>'client_email'],
        'BRVTAL_GA4_PRIVATE_KEY' => ['provider'=>'ga4','field'=>'private_key'],
        'BRVTAL_SENTRY_DSN' => ['provider'=>'sentry','field'=>'dsn'],
        'BRVTAL_TICKET_WOMPI_PUBLIC_KEY' => ['provider'=>'wompi','field'=>'public_key'],
        'BRVTAL_TICKET_WOMPI_PRIVATE_KEY' => ['provider'=>'wompi','field'=>'private_key'],
    ];
}

function brvtalIntegrationSecretMasterKey(?array $privateConfig = null): ?string
{
    $privateConfig ??= is_array($GLOBALS['config'] ?? null) ? $GLOBALS['config'] : [];
    $candidate = $privateConfig['security']['integration_secret_master_key'] ?? null;
    if ($candidate === null || $candidate === '') {
        $candidate = getenv('BRVTAL_INTEGRATION_SECRET_MASTER_KEY');
    }
    if (!is_string($candidate) || $candidate === '') return null;
    // Explicit encodings avoid ambiguous passphrases and weak KDF shortcuts.
    if (preg_match('/^[a-fA-F0-9]{64}$/D', $candidate)) {
        $key = hex2bin($candidate);
    } else {
        $encoded = str_starts_with($candidate, 'base64:') ? substr($candidate, 7) : $candidate;
        $key = base64_decode($encoded, true);
    }
    return is_string($key) && strlen($key) === 32 ? $key : null;
}

function brvtalIntegrationSecretWriteEnabled(?array $privateConfig = null): bool
{
    $privateConfig ??= is_array($GLOBALS['config'] ?? null) ? $GLOBALS['config'] : [];
    if (array_key_exists('integration_secret_write', (array)($privateConfig['security'] ?? []))) {
        return ($privateConfig['security']['integration_secret_write'] ?? null) === true;
    }
    return getenv('BRVTAL_INTEGRATION_SECRET_WRITE_ENABLED') === 'true';
}

function brvtalIntegrationSecretValidate(string $name, string $value): void
{
    if (!isset(brvtalIntegrationSecretCatalog()[$name])) {
        throw new InvalidArgumentException('SECRET_KEY_NOT_ALLOWED');
    }
    if ($value === '' || strlen($value) > 4096 || str_contains($value, "\0")) {
        throw new InvalidArgumentException('SECRET_FORMAT_INVALID');
    }
    $ok = match ($name) {
        'BRVTAL_TICKET_MAIL_API_KEY' => preg_match('/^re_[A-Za-z0-9_-]{8,}$/D', $value) === 1,
        'BRVTAL_TICKET_MAIL_WEBHOOK_SECRET' => preg_match('/^whsec_[A-Za-z0-9_-]{8,}$/D', $value) === 1,
        'BRVTAL_TICKET_WOMPI_PUBLIC_KEY' => preg_match('/^pub_test_[A-Za-z0-9_-]{8,}$/D', $value) === 1,
        'BRVTAL_TICKET_WOMPI_PRIVATE_KEY' => preg_match('/^prv_test_[A-Za-z0-9_-]{8,}$/D', $value) === 1,
        'BRVTAL_TICKET_MAIL_FROM','BRVTAL_MAIL_FROM','BRVTAL_GA4_CLIENT_EMAIL' =>
            filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
        'BRVTAL_TICKET_MAIL_DOMAIN','BRVTAL_SMTP_HOST' =>
            strlen($value) <= 253 && preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])$/iD', $value) === 1
            && !str_contains($value, '..'),
        'BRVTAL_GA4_PROPERTY_ID' => preg_match('/^[0-9]{4,20}$/D', $value) === 1,
        'BRVTAL_GA4_PRIVATE_KEY' => str_contains($value, '-----BEGIN PRIVATE KEY-----')
            && str_contains($value, '-----END PRIVATE KEY-----'),
        'BRVTAL_SENTRY_DSN' => filter_var($value, FILTER_VALIDATE_URL) !== false
            && parse_url($value, PHP_URL_SCHEME) === 'https',
        default => trim($value) !== '',
    };
    if (!$ok) throw new InvalidArgumentException('SECRET_FORMAT_INVALID');
}

function brvtalIntegrationSecretEncrypt(string $name, string $value, string $masterKey): string
{
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($value, 'aes-256-gcm', $masterKey, OPENSSL_RAW_DATA, $nonce, $tag, $name, 16);
    if (!is_string($ciphertext) || strlen($tag) !== 16) {
        throw new RuntimeException('SECRET_ENCRYPT_FAILED');
    }
    return base64_encode($nonce . $tag . $ciphertext);
}

function brvtalIntegrationSecretDecrypt(string $name, string $encoded, string $masterKey): string
{
    $raw = base64_decode($encoded, true);
    if (!is_string($raw) || strlen($raw) < 29) throw new RuntimeException('SECRET_CIPHER_INVALID');
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $masterKey, OPENSSL_RAW_DATA,
        substr($raw, 0, 12), substr($raw, 12, 16), $name);
    if (!is_string($plain)) throw new RuntimeException('SECRET_CIPHER_INVALID');
    return $plain;
}

function brvtalIntegrationSecretRead(string $name, ?PDO $pdo = null, ?array $privateConfig = null): ?string
{
    if (!isset(brvtalIntegrationSecretCatalog()[$name])) return null;
    $master = brvtalIntegrationSecretMasterKey($privateConfig);
    if ($master === null) return null;
    $pdo ??= db();
    $query = $pdo->prepare('SELECT value_encrypted FROM integration_secrets WHERE secret_key=? LIMIT 1');
    $query->execute([$name]);
    $encrypted = $query->fetchColumn();
    return is_string($encrypted) ? brvtalIntegrationSecretDecrypt($name, $encrypted, $master) : null;
}

/** No secret values, ciphertext or suffixes leave this function. */
function brvtalIntegrationSecretStatus(PDO $pdo): array
{
    $stored = [];
    $query = $pdo->query('SELECT secret_key,updated_at FROM integration_secrets');
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $stored[(string)$row['secret_key']] = (string)$row['updated_at'];
    }
    $items = [];
    foreach (brvtalIntegrationSecretCatalog() as $name => $meta) {
        $items[] = ['key'=>$name,'provider'=>$meta['provider'],'field'=>$meta['field'],
            'stored'=>isset($stored[$name]),'updated_at'=>$stored[$name] ?? null];
    }
    return $items;
}

function brvtalIntegrationSecretMutate(PDO $pdo, string $method, string $name, ?string $value, int $adminId): void
{
    $catalog = brvtalIntegrationSecretCatalog();
    if (!isset($catalog[$name])) throw new InvalidArgumentException('SECRET_KEY_NOT_ALLOWED');
    if (!brvtalIntegrationSecretWriteEnabled()) throw new DomainException('SECRET_WRITE_DISABLED');
    $master = brvtalIntegrationSecretMasterKey();
    if ($master === null) throw new DomainException('SECRET_MASTER_KEY_UNAVAILABLE');
    if ($adminId < 1 || !in_array($method, ['POST','DELETE'], true)) {
        throw new DomainException('FORBIDDEN');
    }
    if ($method === 'POST') {
        if (!is_string($value)) throw new InvalidArgumentException('SECRET_FORMAT_INVALID');
        brvtalIntegrationSecretValidate($name, $value);
    }
    // Atomic persistence and audit: fail closed when audit storage is unavailable.
    require_once __DIR__ . '/admin_activity.php';
    $pdo->beginTransaction();
    try {
        if ($method === 'POST') {
            $encrypted = brvtalIntegrationSecretEncrypt($name, (string)$value, $master);
            $statement = $pdo->prepare(
                'INSERT INTO integration_secrets(secret_key,value_encrypted,updated_by_admin_id) VALUES(?,?,?) '
                . 'ON DUPLICATE KEY UPDATE value_encrypted=VALUES(value_encrypted),'
                . 'updated_by_admin_id=VALUES(updated_by_admin_id),updated_at=CURRENT_TIMESTAMP'
            );
            $statement->execute([$name, $encrypted, $adminId]);
        } else {
            $statement = $pdo->prepare('DELETE FROM integration_secrets WHERE secret_key=?');
            $statement->execute([$name]);
        }
        $meta = $catalog[$name];
        brvtal_activity_record($pdo, $method === 'POST' ? 'secret_set' : 'secret_delete',
            'integration_secret', null, null, null,
            ['provider'=>$meta['provider'],'field'=>$meta['field']], $meta['provider']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
