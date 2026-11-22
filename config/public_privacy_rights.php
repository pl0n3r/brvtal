<?php
declare(strict_types=1);

require_once __DIR__ . '/public_cromo_chrome.php';

/**
 * BRVTAL #1352: provisional public rights information.
 * No legal SLA or responsible-party identity is invented.
 */
const BRVTAL_PRIVACY_OWNER_PLACEHOLDER = '[COMPLETAR POR EL DUEÑO]';

/**
 * @return array{
 *   controller_name:string,
 *   controller_email:string,
 *   controller_phone:string,
 *   response_time:string,
 *   controller_domicile:string,
 *   controller_address:string,
 *   rights_contact_area:string,
 *   consultation_time:string,
 *   claim_time:string
 * }
 */
function brvtalPublicPrivacyRightsConfiguration(array $settings): array
{
    $name = trim(is_string($settings['controller_name'] ?? null) ? $settings['controller_name'] : '');
    $email = trim(is_string($settings['controller_email'] ?? null) ? $settings['controller_email'] : '');
    $response = trim(is_string($settings['response_time'] ?? null) ? $settings['response_time'] : '');

    $phone = trim(is_string($settings['controller_phone'] ?? null) ? $settings['controller_phone'] : '');
    $digits = preg_replace('/[^0-9]/', '', $phone);
    $validPhone = $phone !== '' && strlen($phone) <= 40
        && preg_match('/^\+?[0-9][0-9() .-]*$/D', $phone) === 1
        && strlen((string)$digits) >= 7 && strlen((string)$digits) <= 20;
    $extra = ['controller_phone' => $validPhone ? $phone : BRVTAL_PRIVACY_OWNER_PLACEHOLDER];
    foreach (['controller_domicile','controller_address',
              'rights_contact_area','consultation_time','claim_time'] as $key) {
        $raw = $settings[$key] ?? null;
        $value = trim(is_string($raw) ? $raw : '');
        $extra[$key] = $value !== '' ? $value : BRVTAL_PRIVACY_OWNER_PLACEHOLDER;
    }

    return [
        'controller_name' => $name !== '' ? $name : BRVTAL_PRIVACY_OWNER_PLACEHOLDER,
        'controller_email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            ? $email : BRVTAL_PRIVACY_OWNER_PLACEHOLDER,
        'response_time' => $response !== '' ? $response : BRVTAL_PRIVACY_OWNER_PLACEHOLDER,
        ...$extra,
    ];
}

function brvtalPublicPrivacyRightsDocument(
    array $settings,
    string $seoTags = '',
    string $analytics = '',
    string $locale = 'es'
): string {
    $values = brvtalPublicPrivacyRightsConfiguration($settings);
    $escape = static fn(string $text): string => htmlspecialchars(
        $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
    );
    $english = $locale === 'en';
    $labels = $english ? [
        'title' => 'Privacy and my rights',
        'subtitle' => 'Information about personal data and how to request access, correction or erasure.',
        'owner' => 'Data controller',
        'contact' => 'Responsible contact email',
        'phone' => 'Public controller phone',
        'domicile' => 'Legal domicile',
        'address' => 'Contact address',
        'area' => 'Area responsible for data rights',
        'consultation' => 'Consultation response period',
        'claim' => 'Claim response period',
        'rights' => 'Request access, correction or erasure',
        'instructions' => 'Describe the right you want to exercise and use the contact channels available on this page or site. Do not include sensitive information on this public page.',
        'time' => 'Response period indicated by the controller',
        'update' => 'Some contact details and response periods are being updated. For inquiries, use',
        'contact_link' => 'Contact',
        'home' => 'Return to home',
    ] : [
        'title' => 'Privacidad y mis derechos',
        'subtitle' => 'Información sobre datos personales y cómo solicitar acceso, corrección o borrado.',
        'owner' => 'Responsable del tratamiento',
        'contact' => 'Correo de contacto del responsable',
        'phone' => 'Teléfono público del responsable',
        'domicile' => 'Domicilio del responsable',
        'address' => 'Dirección de contacto',
        'area' => 'Área responsable de solicitudes',
        'consultation' => 'Plazo para consultas',
        'claim' => 'Plazo para reclamos',
        'rights' => 'Solicitar acceso, corrección o borrado',
        'instructions' => 'Identifica el derecho que deseas ejercer y utiliza los canales de contacto disponibles en esta página o en el sitio. No compartas datos sensibles aquí.',
        'time' => 'Plazo de respuesta informado por el responsable',
        'update' => 'Algunos datos de contacto del responsable y plazos están en actualización. Para consultas, utiliza',
        'contact_link' => 'Contacto',
        'home' => 'Volver al inicio',
    ];
    // Private configuration can retain owner placeholders; public HTML cannot.
    $isUnresolved = static fn(string $value): bool =>
        trim($value) === '' || preg_match('/\[\s*COMPLETAR\b/iu', $value) === 1;
    $renderField = static function (
        string $key, string $label, string $attribute
    ) use ($values, $escape, $isUnresolved): string {
        $value = $values[$key];
        if ($isUnresolved($value)) {
            return '';
        }
        $visible = $escape($value);
        if ($key === 'controller_email') {
            $visible = '<a href="mailto:' . $escape($value) . '">' . $visible . '</a>';
        }
        return '<h3>' . $escape($label) . '</h3><p ' . $attribute . '>'
            . $visible . '</p>';
    };
    $ownerRow = $isUnresolved($values['controller_name'])
        ? '' : '<p data-privacy-controller>' . $escape($values['controller_name']) . '</p>';
    $hasMissing = count(array_filter($values, $isUnresolved)) > 0;
    $contactNote = $hasMissing
        ? '<p role="note" data-privacy-contact-update>' . $escape($labels['update'])
            . ' <a href="/contact">' . $escape($labels['contact_link']) . '</a>.</p>'
        : '';

    $template = <<<'HTML'
<!doctype html>
<html lang="{{LANG}}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,follow">
<title>{{TITLE}} — BRVTAL</title>
<meta name="description" content="{{SUBTITLE}}">
<link rel="stylesheet" href="/css/public-legibility.css">
<link rel="stylesheet" href="/css/public-visual-identity.css">
<link rel="stylesheet" href="/css/public-cromo.css">
<link rel="stylesheet" href="/css/public-cromo-pages.css">
{{SEO}}
</head>
<body class="brvtal-privacy-rights cromo-page" data-privacy-rights-page>
<a href="#privacy-content" class="skip-link">{{TITLE}}</a>
{{TOPBAR}}
<main id="privacy-content" tabindex="-1" style="max-width:54rem;margin:3rem auto;padding:1.5rem;line-height:1.7">
<h1>{{TITLE}}</h1>
<p>{{SUBTITLE}}</p>
<section aria-labelledby="controller-heading">
<h2 id="controller-heading">{{OWNER_LABEL}}</h2>
{{OWNER_ROW}}
{{CONTACT_ROW}}
{{PHONE_ROW}}
{{DOMICILE_ROW}}
{{ADDRESS_ROW}}
{{CONTACT_NOTE}}
</section>
<section aria-labelledby="rights-heading">
<h2 id="rights-heading">{{RIGHTS_LABEL}}</h2>
<p>{{INSTRUCTIONS}}</p>
{{AREA_ROW}}
{{CONSULTATION_ROW}}
{{CLAIM_ROW}}
{{TIME_ROW}}
</section>
</main>
<footer aria-label="BRVTAL"><span>© BRVTAL</span></footer>
{{ANALYTICS}}
<script src="/js/public-cromo.js" defer></script>
<script src="/js/public-cromo-sound.js" defer></script>
<script src="/js/public-cromo-pages.js" defer></script>
</body>
</html>
HTML;
    return strtr($template, [
        '{{LANG}}' => $english ? 'en' : 'es',
        '{{TITLE}}' => $escape($labels['title']),
        '{{SUBTITLE}}' => $escape($labels['subtitle']),
        '{{SEO}}' => $seoTags,
        '{{HOME}}' => $escape($labels['home']),
        '{{TOPBAR}}' => brvtalPublicCromoTopbar('/', '← ' . $escape($labels['home'])),
        '{{OWNER_LABEL}}' => $escape($labels['owner']),
        '{{OWNER_ROW}}' => $ownerRow,
        '{{CONTACT_LABEL}}' => $escape($labels['contact']),
        '{{CONTACT_ROW}}' => $renderField('controller_email', $labels['contact'], 'data-privacy-email'),
        '{{PHONE_LABEL}}' => $escape($labels['phone']),
        '{{PHONE_ROW}}' => $renderField('controller_phone', $labels['phone'], 'data-privacy-phone'),
        '{{DOMICILE_LABEL}}' => $escape($labels['domicile']),
        '{{DOMICILE_ROW}}' => $renderField('controller_domicile', $labels['domicile'], 'data-privacy-domicile'),
        '{{ADDRESS_LABEL}}' => $escape($labels['address']),
        '{{ADDRESS_ROW}}' => $renderField('controller_address', $labels['address'], 'data-privacy-address'),
        '{{CONTACT_NOTE}}' => $contactNote,
        '{{AREA_LABEL}}' => $escape($labels['area']),
        '{{AREA_ROW}}' => $renderField('rights_contact_area', $labels['area'], 'data-privacy-contact-area'),
        '{{CONSULTATION_LABEL}}' => $escape($labels['consultation']),
        '{{CONSULTATION_ROW}}' => $renderField('consultation_time', $labels['consultation'], 'data-privacy-consultation-time'),
        '{{CLAIM_LABEL}}' => $escape($labels['claim']),
        '{{CLAIM_ROW}}' => $renderField('claim_time', $labels['claim'], 'data-privacy-claim-time'),
        '{{RIGHTS_LABEL}}' => $escape($labels['rights']),
        '{{INSTRUCTIONS}}' => $escape($labels['instructions']),
        '{{TIME_LABEL}}' => $escape($labels['time']),
        '{{TIME_ROW}}' => $renderField('response_time', $labels['time'], 'data-privacy-response-time'),
                '{{ANALYTICS}}' => $analytics,
    ]);
}

/** Apply one visible footer destination to every public HTML route. */
function brvtalPublicPrivacyRightsFooterLink(string $html): string
{
    if (!preg_match('~<body\b~i', $html) || str_contains($html, 'data-privacy-rights-link')) {
        return $html;
    }

    $link = '<a href="/privacy" data-privacy-rights-link="1">PRIVACIDAD Y MIS DERECHOS</a>';
    $withLegacyReplacement = preg_replace(
        '~<a\b[^>]*\bdata-footer-privacy\b[^>]*>.*?</a>~is',
        $link,
        $html,
        1,
        $count
    );
    if ($count > 0 && $withLegacyReplacement !== null) {
        return $withLegacyReplacement;
    }
    if (preg_match('~</footer>~i', $html)) {
        return preg_replace('~</footer>~i', $link . '</footer>', $html, 1) ?? $html;
    }
    return preg_replace(
        '~</body>~i',
        '<footer aria-label="Privacidad">' . $link . '</footer></body>',
        $html,
        1
    ) ?? $html;
}
