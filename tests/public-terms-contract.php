<?php
declare(strict_types=1);

/** BRVTAL #1991: owner-validated terms of sale are public, complete and linked. */
function brvtal_terms_assert(bool $ok, string $what): void
{
    if (!$ok) {
        fwrite(STDERR, "PUBLIC TERMS FAILED: {$what}\n");
        exit(1);
    }
}

require_once __DIR__ . '/../config/public_terms.php';
require_once __DIR__ . '/../config/public_privacy_rights.php';

$html = brvtalPublicTermsDocument('<link rel="canonical" href="https://www.brvtal.com.co/terms">');
brvtal_terms_assert(str_contains($html, '<h1>Términos de venta</h1>'), 'title');
foreach (['1. Quién vende', '2. La compra', '3. Tus boletas', '4. Ingreso al evento', '5. Reventa',
          '6. Cancelación o aplazamiento del evento', '7. Devoluciones y cambios', '8. Reclamos (PQR)',
          '9. Cambios a estos términos'] as $section) {
    brvtal_terms_assert(str_contains($html, $section), "section {$section}");
}
foreach (['Carrera 28 # 80-14, Pereira', 'contacto@brvtal.com.co', 'mayores de 18 años',
          'no incluye IVA', '5 días hábiles', 'a través de Wompi'] as $fact) {
    brvtal_terms_assert(str_contains($html, $fact), "validated fact {$fact}");
}
brvtal_terms_assert(preg_match('/\[\s*(COMPLETAR|PENDIENTE|CONFIRMAR|N\]|CIUDAD|NIT)/iu', $html) === 0,
    'no owner placeholders may reach the public page');
brvtal_terms_assert(!str_contains($html, 'noindex'), 'terms are indexable');
brvtal_terms_assert(str_contains($html, 'href="/privacy"'), 'terms link the privacy policy');

$footer = brvtalPublicPrivacyRightsFooterLink('<html><body><footer>BRVTAL</footer></body></html>');
brvtal_terms_assert(substr_count($footer, 'href="/terms"') === 1 && substr_count($footer, 'href="/privacy"') === 1,
    'every public footer links terms and privacy once');

$page = (string)file_get_contents(__DIR__ . '/../config/public_page.php');
brvtal_terms_assert(str_contains($page, 'Al pagar aceptas los <a href="/terms">términos de venta</a>'),
    'checkout links the terms of sale');
$htaccess = (string)file_get_contents(__DIR__ . '/../.htaccess');
brvtal_terms_assert(str_contains($htaccess, 'RewriteRule ^terms/?$ index.php?page=terms [L,QSA,NC]'), 'route /terms');
$index = (string)file_get_contents(__DIR__ . '/../index.php');
brvtal_terms_assert(str_contains($index, "\$pageRoute === 'terms'"), 'index serves /terms');

echo "BRVTAL #1991 public terms contract passed.\n";
