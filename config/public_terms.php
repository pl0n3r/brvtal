<?php
declare(strict_types=1);

require_once __DIR__ . '/public_cromo_chrome.php';

/**
 * #1991: public terms of sale, validated by the owner on 24-nov-2026.
 * Static, versioned text: no owner placeholders may reach this page.
 */
function brvtalPublicTermsBody(): string
{
    return <<<'HTML'
<p data-terms-updated>Última actualización: 24 de noviembre de 2026</p>
<section aria-labelledby="terms-seller"><h2 id="terms-seller">1. Quién vende</h2>
<p>Las boletas de los eventos publicados en brvtal.com.co las vende <strong>BRVTAL</strong>, con domicilio en Carrera 28 # 80-14, Pereira, Colombia. Contacto: <a href="mailto:contacto@brvtal.com.co">contacto@brvtal.com.co</a>.</p></section>
<section aria-labelledby="terms-purchase"><h2 id="terms-purchase">2. La compra</h2><ul>
<li>Puedes comprar con o sin cuenta. Con la compra aceptas estos términos y la <a href="/privacy">política de privacidad</a>.</li>
<li>El precio que ves al pagar es el total final: no incluye IVA y no hay cobros adicionales al pagar.</li>
<li>Los pagos los procesa Wompi (Bancolombia). BRVTAL nunca ve ni guarda los datos de tu tarjeta o cuenta.</li>
<li>La compra se confirma cuando Wompi aprueba el pago. Si el pago queda pendiente, apartamos tus boletas por un tiempo limitado; si no se aprueba en ese tiempo, el pedido vence y las boletas vuelven a la venta.</li>
<li>Cada pedido tiene un número. Guárdalo para cualquier consulta.</li></ul></section>
<section aria-labelledby="terms-tickets"><h2 id="terms-tickets">3. Tus boletas</h2><ul>
<li>Al aprobarse el pago, te enviamos al correo que indicaste un enlace a cada boleta con su código QR. También las ves y descargas en <a href="/mi-cuenta">Mi cuenta</a>.</li>
<li>Cada QR permite un solo ingreso. Quien presente primero el QR en la puerta es quien entra: no lo compartas ni lo publiques. BRVTAL no responde por QR copiados, reenviados o publicados.</li>
<li>Si no recibes el correo, revisa la carpeta de correo no deseado o escríbenos con tu número de pedido.</li></ul></section>
<section aria-labelledby="terms-entry"><h2 id="terms-entry">4. Ingreso al evento</h2><ul>
<li>Los eventos son solo para mayores de 18 años. Para entrar debes presentar el QR válido y tu documento de identidad original. Podemos negar el ingreso a quien no lo acredite, sin reembolso.</li>
<li>La organización puede hacer requisa y negar el ingreso o retirar a quien ponga en riesgo la seguridad, porte objetos prohibidos o incumpla las normas del lugar, sin reembolso.</li>
<li>Horarios, line-up y lugar pueden ajustarse. Los cambios de artistas o de horario no dan derecho a reembolso, salvo lo dicho en la sección 6.</li></ul></section>
<section aria-labelledby="terms-resale"><h2 id="terms-resale">5. Reventa</h2>
<p>Solo vendemos en brvtal.com.co. No garantizamos boletas compradas a terceros: si el QR ya se usó o fue anulado, no hay ingreso ni reembolso.</p></section>
<section aria-labelledby="terms-cancel"><h2 id="terms-cancel">6. Cancelación o aplazamiento del evento</h2><ul>
<li>Si el evento se cancela, te devolvemos el valor pagado por las boletas por el mismo medio de pago, a través de Wompi.</li>
<li>Si el evento se aplaza o cambia de lugar, tus boletas siguen valiendo para la nueva fecha o lugar. Si no puedes asistir, puedes pedir el reembolso dentro de los 5 días hábiles siguientes al anuncio.</li>
<li>Avisaremos por correo a todos los compradores.</li>
<li>Los tiempos para ver el dinero de vuelta dependen de Wompi y de tu banco o medio de pago.</li></ul></section>
<section aria-labelledby="terms-refunds"><h2 id="terms-refunds">7. Devoluciones y cambios</h2><ul>
<li>Las boletas no tienen devolución ni cambio por decisión del comprador. Solo se reembolsan en los casos de la sección 6 o cuando la ley lo exija.</li>
<li>En esos casos, pides la devolución desde Mi cuenta → Devoluciones. BRVTAL la revisa y, si procede, te reembolsa por el mismo medio de pago a través de Wompi.</li>
<li>Al aprobarse una devolución, las boletas devueltas se anulan y su QR deja de servir.</li></ul></section>
<section aria-labelledby="terms-claims"><h2 id="terms-claims">8. Reclamos (PQR)</h2>
<p>Escríbenos a <a href="mailto:contacto@brvtal.com.co">contacto@brvtal.com.co</a> con tu número de pedido. Respondemos en máximo 15 días hábiles. Si no estás de acuerdo con la respuesta, puedes acudir a la Superintendencia de Industria y Comercio.</p></section>
<section aria-labelledby="terms-changes"><h2 id="terms-changes">9. Cambios a estos términos</h2>
<p>Podemos actualizar estos términos. La versión que aplica a tu compra es la vigente el día en que pagaste.</p></section>
HTML;
}

function brvtalPublicTermsDocument(string $seoTags = '', string $analytics = ''): string
{
    $template = <<<'HTML'
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Términos de venta — BRVTAL</title>
<meta name="description" content="Condiciones de compra, ingreso, cancelación y devoluciones de las boletas de BRVTAL.">
<link rel="stylesheet" href="/css/public-legibility.css">
<link rel="stylesheet" href="/css/public-visual-identity.css">
<link rel="stylesheet" href="/css/public-cromo.css">
<link rel="stylesheet" href="/css/public-cromo-pages.css">
{{SEO}}
</head>
<body class="brvtal-terms cromo-page" data-terms-page>
<a href="#terms-content" class="skip-link">Términos de venta</a>
{{TOPBAR}}
<main id="terms-content" tabindex="-1" style="position:relative;max-width:46rem;margin:3rem auto;padding:2rem 1.5rem;line-height:1.7;background:rgba(7,8,9,.94);color:#f4f1e8;border:1px solid #23282c">
<h1>Términos de venta</h1>
{{BODY}}
</main>
<footer aria-label="BRVTAL"><span>© BRVTAL</span> <a href="/privacy">Privacidad y mis derechos</a></footer>
{{ANALYTICS}}
<script src="/js/public-cromo.js" defer></script>
<script src="/js/public-cromo-sound.js" defer></script>
<script src="/js/public-cromo-pages.js" defer></script>
</body>
</html>
HTML;
    return strtr($template, [
        '{{SEO}}' => $seoTags,
        '{{TOPBAR}}' => brvtalPublicCromoTopbar('/', '← Volver al inicio'),
        '{{BODY}}' => brvtalPublicTermsBody(),
        '{{ANALYTICS}}' => $analytics,
    ]);
}
