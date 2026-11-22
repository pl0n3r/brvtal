<?php
declare(strict_types=1);

require_once __DIR__ . '/integration_secrets_store.php';

/** Private config takes precedence over legacy environment variables. */
function brvtal_secret_value(string $envName, ?array $override = null, ?array $privateConfig = null, ?PDO $secretPdo = null): string
{
    if ($override !== null) {
        $value = $override[$envName] ?? '';
        return is_string($value) ? $value : '';
    }
    if ($privateConfig === null) {
        $loaded = $GLOBALS['config'] ?? null;
        if (!is_array($loaded)) {
            static $fileConfig = null;
            if ($fileConfig === null) {
                $path = __DIR__ . '/config.php';
                $contents = is_file($path) ? require $path : [];
                if (!is_array($contents)) throw new RuntimeException('SECRET_CONFIG_INVALID');
                $fileConfig = $contents;
            }
            $loaded = $fileConfig;
        }
        $privateConfig = $loaded;
    }
    $paths = [
        'BRVTAL_MAIL_FROM' => ['mail','smtp','from'],
        'BRVTAL_MAIL_FROM_NAME' => ['mail','smtp','from_name'],
        'BRVTAL_SENTRY_DSN' => ['observability','sentry_dsn'],
    ];
    if (!isset($paths[$envName])) {
        foreach ([
            'BRVTAL_TICKET_WOMPI_' => ['ticketing','wompi'],
            'BRVTAL_TICKET_MAIL_' => ['ticketing','mail'],
            'BRVTAL_SMTP_' => ['mail','smtp'],
            'BRVTAL_GA4_' => ['analytics','ga4'],
        ] as $prefix => $parent) {
            if (str_starts_with($envName, $prefix)) {
                $paths[$envName] = [...$parent, strtolower(substr($envName, strlen($prefix)))];
                break;
            }
        }
    }
    if (isset($paths[$envName])) {
        $value = $privateConfig;
        foreach ($paths[$envName] as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                $value = null;
                break;
            }
            $value = $value[$part];
        }
        if ($value !== null && $value !== '') {
            if (!is_string($value) && !is_int($value) && !is_bool($value)) {
                throw new RuntimeException('SECRET_CONFIG_INVALID');
            }
            return is_bool($value) ? ($value ? '1' : '0') : (string)$value;
        }
    }
    // Config wins; decrypt only keys on the explicit allowlist.
    if (isset(brvtalIntegrationSecretCatalog()[$envName])) {
        $stored = brvtalIntegrationSecretRead($envName, $secretPdo, $privateConfig);
        if ($stored !== null) return $stored;
    }
    $environment = getenv($envName);
    return is_string($environment) ? $environment : '';
}

/** Credential values never leave the server in status responses. */
function brvtal_provisioning_snapshot(?array $config = null): array
{
    $value = static fn(string $key): string => brvtal_secret_value($key, null, $config);
    $all = (static fn(array $keys): bool => array_all($keys, fn($key) => !(trim($value($key)) === '')));
    $wompi = $all([
        'BRVTAL_TICKET_WOMPI_PUBLIC_KEY','BRVTAL_TICKET_WOMPI_PRIVATE_KEY',
        'BRVTAL_TICKET_WOMPI_INTEGRITY_SECRET','BRVTAL_TICKET_WOMPI_EVENTS_SECRET',
        'BRVTAL_TICKET_WOMPI_RETURN_URL','BRVTAL_TICKET_WOMPI_ENABLED_METHODS',
    ]) && $value('BRVTAL_TICKET_WOMPI_MODE') === 'sandbox'
       && $value('BRVTAL_TICKET_WOMPI_SANDBOX_ENABLED') === '1';
    return [
        'integrations' => [
            ['id'=>'wompi','label'=>'Wompi','status'=>$wompi ? 'modo sandbox' : 'falta'],
            ['id'=>'resend','label'=>'Resend','status'=>$all(['BRVTAL_TICKET_MAIL_API_KEY','BRVTAL_TICKET_MAIL_DOMAIN','BRVTAL_TICKET_MAIL_FROM']) ? 'configurado':'falta'],
            ['id'=>'smtp','label'=>'SMTP','status'=>$all(['BRVTAL_SMTP_HOST','BRVTAL_SMTP_USER','BRVTAL_SMTP_PASSWORD','BRVTAL_MAIL_FROM']) ? 'configurado':'falta'],
            ['id'=>'ga4','label'=>'Google Analytics 4','status'=>$all(['BRVTAL_GA4_PROPERTY_ID','BRVTAL_GA4_CLIENT_EMAIL','BRVTAL_GA4_PRIVATE_KEY']) ? 'configurado':'falta'],
            ['id'=>'sentry','label'=>'Sentry','status'=>trim($value('BRVTAL_SENTRY_DSN')) !== '' ? 'configurado':'falta'],
        ],
        // No heartbeat is written by current workers; never infer a cron run.
        'workers'=>[
            ['id'=>'ticketing-mail','label'=>'Correo de boletas','last_execution'=>null],
            ['id'=>'admin-password-mail','label'=>'Recuperación DISCADMIN','last_execution'=>null],
        ],
    ];
}
