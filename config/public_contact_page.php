<?php
declare(strict_types=1);

/** @return array{title:string,description:string,canonical:string,image:string,schema:array<string,mixed>} */
function brvtal_public_contact_seo(string $base): array
{
    $canonical = rtrim($base, '/') . '/contact';
    $title = 'Contact — BRVTAL';
    $description = 'Contact BRVTAL for bookings, collaborations, events, media and general inquiries from Pereira, Colombia.';
    $image = rtrim($base, '/') . '/assets/brvtal-logo.jpeg';
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
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#050505">
  <meta name="description" content="' . $description . '">
  <title>' . $title . '</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;500;600;700;800;900&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/contact-social.css">
  <link rel="stylesheet" href="css/public-controls.css">
  <link rel="stylesheet" href="css/public-legibility.css">
  <link rel="stylesheet" href="css/public-visual-identity.css">
  <link rel="icon" type="image/jpeg" href="assets/brvtal-logo.jpeg">
  ' . $seoTags . '
</head>
<body class="brvtal-contact-page" data-public-contact-page>
  <a class="contact-skip mono" href="#contactForm">SKIP TO FORM</a>
  <header class="contact-page-nav" aria-label="Contact navigation">
    <a class="contact-page-brand" href="/" aria-label="BRVTAL Home"><strong>BRVTAL</strong><span>RAVE TILL GRAVE</span></a>
    <div class="contact-page-nav-meta mono"><span>CONTACT / 01</span><span>PEREIRA — COLOMBIA</span></div>
    <a class="contact-page-home mono" href="/">HOME ↙</a>
  </header>

  <main class="contact-page-main">
    <section class="contact-page-hero" aria-labelledby="contactTitle">
      <div class="contact-page-grid" aria-hidden="true"></div>
      <div class="contact-page-scan" aria-hidden="true"></div>
      <div class="contact-page-orbit" aria-hidden="true"></div>
      <div class="contact-page-eyebrow mono">DIRECT CHANNEL / BRVTAL</div>
      <h1 id="contactTitle" data-text="CONTACT">CONTACT</h1>
      <p>BOOKINGS, COLLABORATIONS, EVENTS, MEDIA OR GENERAL INQUIRIES. SEND A CLEAN SIGNAL.</p>
      <div class="contact-page-index mono">01 / CONTACT</div>
    </section>

    <section class="contact-page-workspace" aria-label="Contact BRVTAL">
      <aside class="contact-page-context">
        <span class="mono contact-page-kicker">PEREIRA / COLOMBIA</span>
        <h2>OPEN<br><em>CHANNEL.</em></h2>
        <p>Use this channel for booking requests, collaborations, event proposals, press, media and anything that needs a direct BRVTAL response.</p>
        <div class="contact-page-topics" aria-label="Contact topics">
          <span>BOOKINGS</span><span>COLLABORATIONS</span><span>EVENTS</span><span>MEDIA</span><span>GENERAL</span>
        </div>
        <div class="contact-page-socials">
          <span class="mono">EXTERNAL SIGNALS</span>
          <div data-contact-social-mount></div>
        </div>
      </aside>

      <div class="brvtal-contact-shell" id="contactForm">
        <div class="brvtal-contact-intro">
          <span class="mono">CONTACT / DIRECT CHANNEL</span>
          <p>Complete the form below. The anti-bot challenge is issued by BRVTAL and your message is only cleared after confirmed delivery.</p>
        </div>
        <form class="brvtal-contact-form" id="brvtalContactForm" novalidate>
          <div class="brvtal-contact-field"><label for="contactName">NAME</label><input id="contactName" name="name" autocomplete="name" maxlength="100" required><span class="brvtal-contact-error" data-error-for="name"></span></div>
          <div class="brvtal-contact-field"><label for="contactEmail">EMAIL</label><input id="contactEmail" name="email" type="email" autocomplete="email" inputmode="email" maxlength="254" required><span class="brvtal-contact-error" data-error-for="email"></span></div>
          <div class="brvtal-contact-field"><label for="contactSubject">SUBJECT</label><input id="contactSubject" name="subject" maxlength="140" required><span class="brvtal-contact-error" data-error-for="subject"></span></div>
          <div class="brvtal-contact-field"><label for="contactMessage">MESSAGE</label><textarea id="contactMessage" name="message" maxlength="5000" required></textarea><span class="brvtal-contact-error" data-error-for="message"></span></div>
          <label class="brvtal-contact-hp" aria-hidden="true">Website<input name="website" tabindex="-1" autocomplete="off"></label>
          <div class="brvtal-captcha">
            <div class="brvtal-captcha-copy"><span>ANTI-BOT CHECK</span><strong data-contact-captcha-question>LOADING…</strong><small>ENTER THE RESULT TO CONFIRM YOU ARE HUMAN.</small></div>
            <div><label class="brvtal-contact-hp" for="contactCaptcha">CAPTCHA ANSWER</label><input id="contactCaptcha" class="brvtal-captcha-input" name="captcha_answer" inputmode="numeric" pattern="[0-9]*" autocomplete="off" aria-label="CAPTCHA answer" required><span class="brvtal-contact-error" data-error-for="captcha"></span></div>
          </div>
          <input type="hidden" name="captcha_token" data-contact-captcha-token>
          <div class="brvtal-contact-actions"><button class="brvtal-contact-submit" type="submit">SEND SIGNAL ↗</button><div class="brvtal-contact-status" role="status" aria-live="polite" data-contact-status>READY / WAITING FOR SIGNAL</div></div>
        </form>
      </div>
    </section>
  </main>

  <footer class="contact-page-footer mono"><span>© 2026 BRVTAL</span><a href="/">BACK TO HOME</a><span>RAVE TILL GRAVE</span></footer>
  <script src="js/public-contact.js"></script>
  ' . $analytics . '
</body>
</html>';
}
