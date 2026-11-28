<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/marketing_consent.php';

/** The token is the capability. No email, account or session is required. */
function brvtalMarketingUnsubscribeToken(mixed $value): ?string
{
    return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1 ? $value : null;
}

/** RFC 8058 POSTs may carry the capability in the URL; browser forms use the body. */
function brvtalMarketingUnsubscribePostToken(array $query, array $form): ?string
{
    if (array_key_exists('token', $form)) {
        return array_key_exists('token', $query) ? null : brvtalMarketingUnsubscribeToken($form['token']);
    }
    return ($form['List-Unsubscribe'] ?? null) === 'One-Click'
        ? brvtalMarketingUnsubscribeToken($query['token'] ?? null)
        : null;
}

/** @return array<string,string> */
function brvtalMarketingUnsubscribeHeaders(): array
{
    return [
        'Content-Type' => 'text/html; charset=UTF-8',
        'Cache-Control' => 'no-store, private',
        'X-Robots-Tag' => 'noindex, nofollow',
        'Referrer-Policy' => 'no-referrer',
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => "default-src 'none'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
    ];
}

function brvtalMarketingUnsubscribePage(string $content): string
{
    return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>BRVTAL | Baja de marketing</title></head><body><main>'
        . '<h1>Baja de correos de marketing</h1>' . $content . '</main></body></html>';
}

function brvtalMarketingUnsubscribeResponse(string $method, array $query, array $form, callable $revoke): array
{
    $headers = brvtalMarketingUnsubscribeHeaders();
    $neutral = '<p>Si el enlace era válido y la baja está habilitada, se ha revocado el consentimiento correspondiente.</p>';
    $status = 200;
    $content = $neutral;

    if ($method === 'GET') {
        $token = brvtalMarketingUnsubscribeToken($query['token'] ?? null);
        if ($token !== null) {
            $content = '<p>Confirma la baja de correos de marketing. Los correos transaccionales no cambian.</p>'
                . '<form method="post" action="/api/marketing-unsubscribe.php">'
                . '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
                . '<button type="submit">Confirmar baja</button></form>';
        }
    } elseif ($method === 'POST') {
        $token = brvtalMarketingUnsubscribePostToken($query, $form);
        if ($token !== null) {
            try {
                $revoke($token);
            } catch (Throwable) {
                // Do not emit exceptions, tokens, email addresses or storage details.
                $status = 503;
                $content = '<p>No se pudo procesar la solicitud en este momento.</p>';
            }
        }
    } else {
        $headers['Allow'] = 'GET, POST';
        $status = 405;
    }

    return ['status' => $status, 'headers' => $headers, 'body' => brvtalMarketingUnsubscribePage($content)];
}

/** #1671: do not even connect to non-disposable storage before legal activation. */
function brvtalMarketingUnsubscribeRevoke(string $token): void
{
    if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
        return;
    }
    global $config;
    require_once __DIR__ . '/../config/bootstrap.php';
    $name = $config['db']['name'] ?? null;
    if (is_string($name) && preg_match('/^brvtal_test[A-Za-z0-9_]*$/D', $name) === 1) {
        brvtalMarketingConsentRevoke(db(), $token);
    }
}

if (PHP_SAPI !== 'cli') {
    $response = brvtalMarketingUnsubscribeResponse(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        $_GET,
        $_POST,
        'brvtalMarketingUnsubscribeRevoke'
    );
    http_response_code($response['status']);
    foreach ($response['headers'] as $name => $value) {
        header($name . ': ' . $value);
    }
    echo $response['body'];
}
