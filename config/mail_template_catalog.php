<?php
declare(strict_types=1);

/**
 * #1886. Allowlisted editable copy, not a router or a delivery trigger.
 * Token-bearing links, recipients, QR codes, order IDs and provider details
 * remain exclusively at the trusted transport/rendering boundary.
 */
function brvtal_mail_template_catalog(): array
{
    $copy = require __DIR__ . '/../templates/ticketing/transactional.php';
    $ticketKeys = [
        'verify_account', 'reset_password', 'order_pending', 'order_rejected',
        'payment_approved', 'reminder', 'refund',
    ];
    $catalog = [];
    foreach ($ticketKeys as $key) {
        if (!isset($copy[$key]) || !is_array($copy[$key])) {
            throw new LogicException('MAIL_TEMPLATE_SOURCE_UNAVAILABLE');
        }
        $catalog[$key] = [
            'scope' => 'ticketing',
            'allowed_variables' => in_array($key, ['verify_account', 'reset_password'], true)
                ? [] : ['event_name'],
            'server_only' => ['link', 'token', 'recipient', 'email', 'buyer_id',
                'order_id', 'ticket_id', 'qr', 'cta_url', 'payment_reference'],
            'defaults' => [
                'subject' => (string)$copy[$key]['subject'],
                'preheader' => '',
                'title' => (string)$copy[$key]['title'],
                'message' => (string)$copy[$key]['message'],
                'cta' => (string)$copy[$key]['cta'],
                'footer' => 'BRVTAL · Mensaje transaccional, no publicitario.',
                'theme' => brvtal_mail_template_default_theme(),
            ],
        ];
    }
    // Existing staff password recovery/changed notices only; no invented event.
    $staff = [
        'staff_password_recovery' => [
            'subject' => 'Recupera tu acceso a BRVTAL',
            'title' => 'Recupera tu acceso',
            'message' => 'Solicitaste recuperar tu acceso a BRVTAL. El enlace vence en 60 minutos y solo puede usarse una vez.',
            'cta' => 'Recuperar acceso',
        ],
        'staff_password_changed' => [
            'subject' => 'Tu contraseña de BRVTAL cambió',
            'title' => 'Contraseña actualizada',
            'message' => 'La contraseña de tu cuenta BRVTAL acaba de cambiar. Si no fuiste tú, contacta de inmediato al administrador del sitio.',
            'cta' => 'Abrir DISCADMIN',
        ],
    ];
    foreach ($staff as $key => $entry) {
        $catalog[$key] = [
            'scope' => 'staff',
            'allowed_variables' => [],
            'server_only' => ['reset_link','token','email','recipient','admin_id','password','credentials'],
            'defaults' => [
                'subject' => $entry['subject'], 'preheader' => '',
                'title' => $entry['title'], 'message' => $entry['message'],
                'cta' => $entry['cta'],
                'footer' => 'BRVTAL · Aviso de seguridad.',
                'theme' => brvtal_mail_template_default_theme(),
            ],
        ];
    }
    $catalog['brand_theme'] = [
        'scope' => 'global',
        'allowed_variables' => [],
        'server_only' => [],
        'defaults' => ['theme' => brvtal_mail_template_default_theme()],
    ];
    return $catalog;
}

function brvtal_mail_template_default_theme(): array
{
    return [
        'background' => '#111111', 'foreground' => '#f7f7f7', 'accent' => '#ffffff',
        'logo_media_id' => null, 'hero_media_id' => null,
    ];
}

function brvtal_mail_template_definition(string $key, string $scope): array
{
    $definition = brvtal_mail_template_catalog()[$key] ?? null;
    if (!is_array($definition) || $definition['scope'] !== $scope) {
        throw new InvalidArgumentException('MAIL_TEMPLATE_UNKNOWN');
    }
    return $definition;
}

/**
 * Strict document validation. No HTML/CSS/JS/URLs/credentials or personal data
 * can enter either the document or a mail send; Media requires an authorized
 * resolver supplied by the authenticated API in a subsequent leaf.
 */
function brvtal_mail_template_validate(
    string $key,
    string $scope,
    array $document,
    ?callable $mediaAuthorized = null
): array {
    $definition = brvtal_mail_template_definition($key, $scope);
    $isTheme = $key === 'brand_theme';
    $fields = $isTheme ? ['theme'] :
        ['subject','preheader','title','message','cta','footer','theme'];
    foreach (array_keys($document) as $field) {
        if (!in_array($field, $fields, true)) {
            throw new InvalidArgumentException('MAIL_TEMPLATE_FIELD_FORBIDDEN');
        }
    }
    $normalized = [];
    $max = ['subject'=>180,'preheader'=>180,'title'=>160,'message'=>4000,
        'cta'=>80,'footer'=>500];
    foreach ($max as $field => $limit) {
        if ($isTheme) break;
        $value = $document[$field] ?? null;
        if (!is_string($value) || ($field !== 'preheader' && $field !== 'footer' && trim($value) === '')
            || mb_strlen($value, 'UTF-8') > $limit
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)
            || ($field !== 'message' && $field !== 'footer' && preg_match('/[\r\n]/', $value))
        ) {
            throw new InvalidArgumentException('MAIL_TEMPLATE_TEXT_INVALID');
        }
        if (str_contains($value, '<') || str_contains($value, '>')
            || preg_match('~(?:https?://|www\.|(?:data|javascript|vbscript):|@|\\b(?:bearer|api[_-]?key|token\\s*=|password\\s*=|whsec_|sk_live_|sk_test_)\\b)~iu', $value)
            || preg_match('/\b[0-9]{10,}\b/', $value)
        ) {
            throw new InvalidArgumentException('MAIL_TEMPLATE_UNSAFE_CONTENT');
        }
        preg_match_all('/\{\{([a-z_]{1,40})\}\}/', $value, $matches);
        foreach ($matches[1] as $variable) {
            if (!in_array($variable, $definition['allowed_variables'], true)) {
                throw new InvalidArgumentException('MAIL_TEMPLATE_VARIABLE_FORBIDDEN');
            }
        }
        $withoutVariables = preg_replace('/\{\{[a-z_]{1,40}\}\}/', '', $value);
        if (str_contains($withoutVariables ?? '', '{') || str_contains($withoutVariables ?? '', '}')) {
            throw new InvalidArgumentException('MAIL_TEMPLATE_VARIABLE_FORBIDDEN');
        }
        $normalized[$field] = trim($value);
    }
    $theme = $document['theme'] ?? null;
    if (!is_array($theme) || array_keys($theme) !== array_keys(brvtal_mail_template_default_theme())) {
        throw new InvalidArgumentException('MAIL_TEMPLATE_THEME_INVALID');
    }
    $colors = ['background','foreground','accent'];
    foreach ($colors as $color) {
        if (!is_string($theme[$color]) || !preg_match('/^#[0-9a-fA-F]{6}$/D', $theme[$color])) {
            throw new InvalidArgumentException('MAIL_TEMPLATE_COLOR_INVALID');
        }
    }
    foreach (['logo_media_id','hero_media_id'] as $media) {
        $id = $theme[$media];
        if ($id !== null && (!is_int($id) || $id < 1 || $mediaAuthorized === null
            || $mediaAuthorized($id) !== true)) {
            throw new InvalidArgumentException('MAIL_TEMPLATE_MEDIA_FORBIDDEN');
        }
    }
    $normalized['theme'] = [
        'background'=>strtolower($theme['background']),
        'foreground'=>strtolower($theme['foreground']),
        'accent'=>strtolower($theme['accent']),
        'logo_media_id'=>$theme['logo_media_id'],
        'hero_media_id'=>$theme['hero_media_id'],
    ];
    return $normalized;
}
