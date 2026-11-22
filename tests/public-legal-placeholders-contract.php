<?php
declare(strict_types=1);

/** BRVTAL #1862: public render contract must not publish editorial placeholders. */
function brvtal_legal_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "PUBLIC LEGAL PLACEHOLDERS FAILED: {$message}\n");
        exit(1);
    }
}

require_once __DIR__ . '/../config/public_privacy_rights.php';
$incomplete = [
    'controller_name' => 'Colectivo [COMPLETAR POR EL DUEÑO]',
    'controller_email' => 'javascript:alert(1)',
    'controller_phone' => '+12',
    'response_time' => '[ Completar el plazo ]',
    'controller_address' => '[COMPLETAR]',
];
foreach (['es', 'en'] as $locale) {
    foreach ([[], $incomplete] as $settings) {
        $html = brvtalPublicPrivacyRightsDocument($settings, locale: $locale);
        brvtal_legal_assert(
            preg_match('/\[\s*COMPLETAR\b/iu', $html) === 0,
            "{$locale}: incomplete owner markers must never reach HTML"
        );
        brvtal_legal_assert(
            stripos($html, 'pendiente de aprobación') === false
                && stripos($html, 'pending professional approval') === false,
            "{$locale}: internal legal status must never reach HTML"
        );
        brvtal_legal_assert(
            str_contains($html, 'data-privacy-contact-update')
                && str_contains($html, 'href="/contact"')
                && str_contains($html, '<meta name="robots" content="noindex,follow">'),
            "{$locale}: missing fields must show neutral text and existing contact channel"
        );
        brvtal_legal_assert(
            !str_contains($html, 'mailto:javascript:'),
            "{$locale}: invalid mailto must never be emitted"
        );
    }
}

$complete = [
    'controller_name' => 'Responsable sintético',
    'controller_email' => 'privacy@example.invalid',
    'controller_phone' => '+57 (601) 123-4567',
    'controller_domicile' => 'Ciudad de prueba',
    'controller_address' => 'Calle ficticia 1',
    'rights_contact_area' => 'Área de pruebas',
    'consultation_time' => 'Periodo sintético A',
    'claim_time' => 'Periodo sintético B',
    'response_time' => 'Periodo sintético C',
];
$html = brvtalPublicPrivacyRightsDocument($complete);
foreach ([
    'data-privacy-controller', 'data-privacy-email', 'data-privacy-phone',
    'data-privacy-domicile', 'data-privacy-address', 'data-privacy-contact-area',
    'data-privacy-consultation-time', 'data-privacy-claim-time',
    'data-privacy-response-time',
] as $attribute) {
    brvtal_legal_assert(str_contains($html, $attribute), "{$attribute}: valid data remains visible");
}
brvtal_legal_assert(
    str_contains($html, 'mailto:privacy@example.invalid')
        && !str_contains($html, 'data-privacy-contact-update'),
    'complete synthetic data must not show a fallback note'
);

$escaped = brvtalPublicPrivacyRightsDocument([
    'controller_name' => '<script>alert(1)</script>',
    'controller_address' => '<img src=x onerror=alert(1)>',
]);
brvtal_legal_assert(
    !str_contains($escaped, '<script>alert(1)</script>')
        && !str_contains($escaped, '<img src=x')
        && str_contains($escaped, '&lt;script&gt;'),
    'user-supplied values must remain HTML escaped'
);

$cookie = (string)file_get_contents(__DIR__ . '/../js/cookie-consent.js');
brvtal_legal_assert(
    !str_contains($cookie, 'Texto legal pendiente de aprobación profesional')
        && str_contains($cookie, 'Las cookies opcionales solo se activan si las aceptas.')
        && str_contains($cookie, "reject.dataset.cookieReject = ''")
        && str_contains($cookie, "accept.dataset.cookieAccept = ''"),
    'runtime cookie banner must not display internal legal status'
);
echo "BRVTAL public legal placeholder contract passed.\n";
