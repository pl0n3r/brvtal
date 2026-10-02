<?php
declare(strict_types=1);

require_once __DIR__ . '/seo_workspace.php';

/** @return array{title:string,description:string,canonical:string,image:string,schema:array<string,mixed>} */
function brvtal_public_contact_seo(string $base, ?PDO $pdo = null): array // NOSONAR legacy public API name
{
    $state = brvtalSeoWorkspaceStaticState($pdo, 'contact', $base);
    $canonical = (string)$state['canonical'];
    $title = (string)$state['effective_title'];
    $description = (string)$state['effective_description'];
    $image = (string)$state['effective_image'];
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'ContactPage',
        'name' => 'Contact BRVTAL',
        'url' => $canonical,
        'image' => $image,
        'description' => $description,
    ];

    return compact('title', 'description', 'canonical', 'image', 'schema');
}

function brvtal_public_contact_page(array $seo, string $analytics = ''): string
{
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $title = $escape((string)($seo['title'] ?? 'Contact — BRVTAL'));
    $description = $escape((string)($seo['description'] ?? 'Contact BRVTAL.'));
    $seoTags = brvtal_public_seo_tags($seo);

    return '<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#050505">
  <meta name="description" content="' . $description . '">
  <title>' . $title . '</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link
    href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;500;600;700;800;900&family=Space+Mono:wght@400;700&display=swap"
    rel="stylesheet">
  <link rel="stylesheet" href="/css/contact-social.css">
  <link rel="stylesheet" href="/css/public-controls.css">
  <link rel="stylesheet" href="/css/public-legibility.css">
  <link rel="stylesheet" href="/css/public-visual-identity.css">
  <link rel="icon" type="image/jpeg" href="/assets/brvtal-logo.jpeg">
  ' . $seoTags . '
</head>
<body class="brvtal-contact-page" data-public-contact-page>
  <a class="contact-skip mono"
     href="#contactForm"
     data-contact-i18n-key="contact.skip">IR AL FORMULARIO</a>
  <header class="contact-page-nav" aria-label="Contact navigation">
    <a class="contact-page-brand" href="/" aria-label="BRVTAL Home">
      <strong>BRVTAL</strong><span>RAVE TILL GRAVE</span>
    </a>
    <div class="contact-page-nav-meta mono">
      <span data-contact-i18n-key="contact.nav">CONTACTO / 01</span>
      <span>PEREIRA — COLOMBIA</span>
    </div>
    <a class="contact-page-home mono" href="/" data-contact-i18n-key="contact.home">INICIO ↙</a>
  </header>

  <main class="contact-page-main">
    <section class="contact-page-hero" aria-labelledby="contactTitle">
      <div class="contact-page-grid" aria-hidden="true"></div>
      <div class="contact-page-scan" aria-hidden="true"></div>
      <div class="contact-page-orbit" aria-hidden="true"></div>
      <div class="contact-page-eyebrow mono" data-contact-i18n-key="contact.channel">CANAL DIRECTO / BRVTAL</div>
      <h1 id="contactTitle" data-text="CONTACTO" data-contact-i18n-key="contact.title">CONTACTO</h1>
      <p data-contact-i18n-key="contact.hero_copy">
        BOOKINGS, COLABORACIONES, EVENTOS, MEDIA O CONSULTAS GENERALES. ENVÍA UNA SEÑAL CLARA.
      </p>
      <div class="contact-page-index mono">01 / CONTACT</div>
    </section>

    <section class="contact-page-workspace" aria-label="Contact BRVTAL">
      <aside class="contact-page-context">
        <span class="mono contact-page-kicker" data-contact-i18n-key="contact.kicker">PEREIRA / COLOMBIA</span>
        <h2>
          <span data-contact-i18n-key="contact.heading_top">ABRE</span><br>
          <em data-contact-i18n-key="contact.heading_bottom">CANAL.</em>
        </h2>
        <p data-contact-i18n-key="contact.copy">
          Usa este canal para bookings, colaboraciones, propuestas de eventos, prensa, media
          y cualquier solicitud que necesite una respuesta directa de BRVTAL.
        </p>
        <div class="contact-page-topics" aria-label="Contact topics">
          <span data-contact-i18n-key="contact.topic.bookings">BOOKINGS</span>
          <span data-contact-i18n-key="contact.topic.collabs">COLABORACIONES</span>
          <span data-contact-i18n-key="contact.topic.events">EVENTOS</span>
          <span data-contact-i18n-key="contact.topic.media">MEDIA</span>
          <span data-contact-i18n-key="contact.topic.general">GENERAL</span>
        </div>
        <div class="contact-page-socials">
          <span class="mono" data-contact-i18n-key="contact.external">SEÑALES EXTERNAS</span>
          <div data-contact-social-mount></div>
        </div>
      </aside>

      <div class="brvtal-contact-shell" id="contactForm">
        <div class="brvtal-contact-intro">
          <span class="mono" data-contact-i18n-key="contact.direct">CONTACTO / CANAL DIRECTO</span>
          <p data-contact-i18n-key="contact.instructions">
            Completa el formulario. El desafío anti-bot es emitido por BRVTAL
            y tu mensaje solo se confirma después de una entrega válida.
          </p>
        </div>
        <form class="brvtal-contact-form" id="brvtalContactForm" novalidate>
          <div class="brvtal-contact-field">
            <label for="contactName" data-contact-i18n-key="contact.name">NOMBRE</label>
            <input id="contactName" name="name" autocomplete="name" maxlength="100" required>
            <span class="brvtal-contact-error" data-error-for="name"></span>
          </div>
          <div class="brvtal-contact-field">
            <label for="contactEmail" data-contact-i18n-key="contact.email">EMAIL</label>
            <input id="contactEmail" name="email" type="email" autocomplete="email"
              inputmode="email" maxlength="254" required>
            <span class="brvtal-contact-error" data-error-for="email"></span>
          </div>
          <div class="brvtal-contact-field">
            <label for="contactSubject" data-contact-i18n-key="contact.subject">ASUNTO</label>
            <input id="contactSubject" name="subject" maxlength="140" required>
            <span class="brvtal-contact-error" data-error-for="subject"></span>
          </div>
          <div class="brvtal-contact-field">
            <label for="contactMessage" data-contact-i18n-key="contact.message">MENSAJE</label>
            <textarea id="contactMessage" name="message" maxlength="5000" required></textarea>
            <span class="brvtal-contact-error" data-error-for="message"></span>
          </div>
          <label class="brvtal-contact-hp" aria-hidden="true">
            Website
            <input name="website" tabindex="-1" autocomplete="off">
          </label>
          <div class="brvtal-captcha">
            <div class="brvtal-captcha-copy">
              <span data-contact-i18n-key="contact.captcha">CONTROL ANTI-BOT</span>
              <strong data-contact-captcha-question>CARGANDO…</strong>
              <small data-contact-i18n-key="contact.captcha_help">
                INGRESA EL RESULTADO PARA CONFIRMAR QUE ERES HUMANO.
              </small>
            </div>
            <div>
              <label class="brvtal-contact-hp" for="contactCaptcha">CAPTCHA ANSWER</label>
              <input id="contactCaptcha" class="brvtal-captcha-input"
                name="captcha_answer" inputmode="numeric" pattern="[0-9]*"
                autocomplete="off" aria-label="CAPTCHA answer" required>
              <span class="brvtal-contact-error" data-error-for="captcha"></span>
            </div>
          </div>
          <input type="hidden" name="captcha_token" data-contact-captcha-token>
          <div class="brvtal-contact-actions">
            <button class="brvtal-contact-submit" type="submit"
              data-contact-i18n-key="contact.submit">ENVIAR SEÑAL ↗</button>
            <div class="brvtal-contact-status" role="status" aria-live="polite"
              data-contact-status data-contact-i18n-key="contact.ready">
              LISTO / ESPERANDO SEÑAL
            </div>
          </div>
        </form>
      </div>
    </section>
  </main>

  <footer class="contact-page-footer mono">
    <span>© 2026 BRVTAL</span>
    <a href="/" data-contact-i18n-key="contact.footer_home">VOLVER AL INICIO</a>
    <span>RAVE TILL GRAVE</span>
  </footer>
  <script src="/js/public-contact.js"></script>
  ' . $analytics . '
</body>
</html>';
}
