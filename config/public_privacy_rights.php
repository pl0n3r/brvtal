<?php
declare(strict_types=1);

/**
 * BRVTAL #1352: provisional public rights information.
 * No legal SLA or responsible-party identity is invented.
 */
const BRVTAL_PRIVACY_OWNER_PLACEHOLDER = '[COMPLETAR POR EL DUEÑO]';
const BRVTAL_PRIVACY_LEGAL_STATUS = 'Texto legal pendiente de aprobación profesional (Condor #974).';

/** @return array{controller_name:string,controller_email:string,response_time:string} */
function brvtalPublicPrivacyRightsConfiguration(array $settings): array
{
    $name = trim(is_string($settings['controller_name'] ?? null) ? $settings['controller_name'] : '');
    $email = trim(is_string($settings['controller_email'] ?? null) ? $settings['controller_email'] : '');
    $response = trim(is_string($settings['response_time'] ?? null) ? $settings['response_time'] : '');

    return [
        'controller_name' => $name !== '' ? $name : BRVTAL_PRIVACY_OWNER_PLACEHOLDER,
        'controller_email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            ? $email : BRVTAL_PRIVACY_OWNER_PLACEHOLDER,
        'response_time' => $response !== '' ? $response : BRVTAL_PRIVACY_OWNER_PLACEHOLDER,
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
        'rights' => 'Request access, correction or erasure',
        'instructions' => 'Contact the controller by the email indicated above. Specify the right you want to exercise. Do not post sensitive data on this public page.',
        'time' => 'Response period indicated by the controller',
        'pending' => 'Legal text pending professional approval (Condor #974).',
        'home' => 'Return to home',
    ] : [
        'title' => 'Privacidad y mis derechos',
        'subtitle' => 'Información sobre datos personales y cómo solicitar acceso, corrección o borrado.',
        'owner' => 'Responsable del tratamiento',
        'contact' => 'Correo de contacto del responsable',
        'rights' => 'Solicitar acceso, corrección o borrado',
        'instructions' => 'Escribe al correo indicado arriba e identifica el derecho que deseas ejercer. No publiques datos sensibles en esta página.',
        'time' => 'Plazo de respuesta informado por el responsable',
        'pending' => BRVTAL_PRIVACY_LEGAL_STATUS,
        'home' => 'Volver al inicio',
    ];
    $email = $values['controller_email'];
    $emailHtml = $escape($email);
    if ($email !== BRVTAL_PRIVACY_OWNER_PLACEHOLDER) {
        $emailHtml = '<a href="mailto:' . $escape($email) . '">' . $emailHtml . '</a>';
    }
    $template = <<<'HTML'
<!doctype html>
<html lang="{{LANG}}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="index,follow">
<title>{{TITLE}} — BRVTAL</title>
<meta name="description" content="{{SUBTITLE}}">
<link rel="stylesheet" href="/css/public-legibility.css">
<link rel="stylesheet" href="/css/public-visual-identity.css">
{{SEO}}
</head>
<body class="brvtal-privacy-rights" data-privacy-rights-page>
<a href="#privacy-content" class="skip-link">{{TITLE}}</a>
<header><nav aria-label="BRVTAL"><a href="/">{{HOME}}</a></nav></header>
<main id="privacy-content" tabindex="-1" style="max-width:54rem;margin:3rem auto;padding:1.5rem;line-height:1.7">
<h1>{{TITLE}}</h1>
<p>{{SUBTITLE}}</p>
<section aria-labelledby="controller-heading">
<h2 id="controller-heading">{{OWNER_LABEL}}</h2>
<p data-privacy-controller>{{OWNER_VALUE}}</p>
<h3>{{CONTACT_LABEL}}</h3>
<p data-privacy-email>{{EMAIL_VALUE}}</p>
</section>
<section aria-labelledby="rights-heading">
<h2 id="rights-heading">{{RIGHTS_LABEL}}</h2>
<p>{{INSTRUCTIONS}}</p>
<h3>{{TIME_LABEL}}</h3>
<p data-privacy-response-time>{{TIME_VALUE}}</p>
</section>
<p role="note" data-privacy-legal-pending>{{PENDING}}</p>
</main>
<footer aria-label="BRVTAL"><span>© BRVTAL</span></footer>
{{ANALYTICS}}
</body>
</html>
HTML;
    return strtr($template, [
        '{{LANG}}' => $english ? 'en' : 'es',
        '{{TITLE}}' => $escape($labels['title']),
        '{{SUBTITLE}}' => $escape($labels['subtitle']),
        '{{SEO}}' => $seoTags,
        '{{HOME}}' => $escape($labels['home']),
        '{{OWNER_LABEL}}' => $escape($labels['owner']),
        '{{OWNER_VALUE}}' => $escape($values['controller_name']),
        '{{CONTACT_LABEL}}' => $escape($labels['contact']),
        '{{EMAIL_VALUE}}' => $emailHtml,
        '{{RIGHTS_LABEL}}' => $escape($labels['rights']),
        '{{INSTRUCTIONS}}' => $escape($labels['instructions']),
        '{{TIME_LABEL}}' => $escape($labels['time']),
        '{{TIME_VALUE}}' => $escape($values['response_time']),
        '{{PENDING}}' => $escape($labels['pending']),
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
