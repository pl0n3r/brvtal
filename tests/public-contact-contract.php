<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_contact.php';
require_once __DIR__ . '/../config/public_seo.php';
require_once __DIR__ . '/../config/public_contact_page.php';
require_once __DIR__ . '/../config/public_sitemap.php';

function contact_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$config = [
    'security' => ['csrf_key' => str_repeat('contact-contract-secret-', 3)],
    'contact' => [
        'to' => 'inbox@example.com',
        'trusted_proxies' => ['203.0.113.0/24', '2001:db8::/32'],
    ],
];
$now = 1_800_000_000;
$challenge = brvtal_contact_issue_challenge($config, $now);
contact_assert(str_contains((string)$challenge['question'], '+'), 'challenge exposes a human-readable arithmetic prompt');
contact_assert((int)$challenge['expires_in'] === 600, 'challenge expiry is explicit');

$decoded = brvtal_contact_decode_challenge((string)$challenge['token'], $config, $now);
contact_assert(is_array($decoded), 'signed challenge decodes');
$answer = (int)$decoded['a'] + (int)$decoded['b'];
contact_assert(brvtal_contact_verify_challenge((string)$challenge['token'], $answer, $config, $now + 3), 'correct CAPTCHA answer verifies');
contact_assert(!brvtal_contact_verify_challenge((string)$challenge['token'] . 'x', $answer, $config, $now + 3), 'tampered CAPTCHA token is rejected');
contact_assert(!brvtal_contact_verify_challenge((string)$challenge['token'], $answer + 1, $config, $now + 3), 'wrong CAPTCHA answer is rejected');
contact_assert(brvtal_contact_decode_challenge((string)$challenge['token'], $config, $now + 601) === null, 'expired CAPTCHA token is rejected');

$payload = [
    'name' => 'Felipe Test',
    'email' => 'felipe@example.com',
    'subject' => 'Booking inquiry',
    'message' => 'This is a sufficiently long contact message.',
    'website' => '',
    'captcha_token' => (string)$challenge['token'],
    'captcha_answer' => (string)$answer,
];
$valid = brvtal_contact_validate_payload($payload, $config, $now + 3);
contact_assert($valid['ok'] === true, 'valid contact payload passes server-side validation');
contact_assert($valid['errors'] === [], 'valid contact payload has no field errors');

$tooFast = brvtal_contact_validate_payload($payload, $config, $now + 1);
contact_assert(($tooFast['errors']['captcha'] ?? '') === 'CAPTCHA_TOO_FAST', 'timing heuristic rejects instant automated submission');

$bad = $payload;
$bad['email'] = 'not-an-email';
$bad['message'] = 'short';
$bad['website'] = 'spam.example';
$invalid = brvtal_contact_validate_payload($bad, $config, $now + 3);
contact_assert(($invalid['errors']['email'] ?? '') === 'INVALID_EMAIL', 'invalid email is rejected');
contact_assert(($invalid['errors']['message'] ?? '') === 'INVALID_MESSAGE', 'short message is rejected');
contact_assert(($invalid['errors']['form'] ?? '') === 'BOT_DETECTED', 'honeypot is enforced server-side');

$originalRemote = $_SERVER['REMOTE_ADDR'] ?? null;
$originalForwarded = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null;
try {
    $_SERVER['REMOTE_ADDR'] = '198.51.100.10';
    $_SERVER['HTTP_CF_CONNECTING_IP'] = '192.0.2.44';
    contact_assert(
        brvtal_contact_client_key($config) === hash('sha256', '198.51.100.10'),
        'untrusted peers cannot spoof CF-Connecting-IP rate-limit buckets'
    );

    $_SERVER['REMOTE_ADDR'] = '203.0.113.8';
    $_SERVER['HTTP_CF_CONNECTING_IP'] = '192.0.2.44';
    contact_assert(
        brvtal_contact_client_key($config) === hash('sha256', '192.0.2.44'),
        'trusted IPv4 proxy CIDRs may supply a validated CF-Connecting-IP address'
    );

    $_SERVER['REMOTE_ADDR'] = '2001:db8::8';
    $_SERVER['HTTP_CF_CONNECTING_IP'] = '2001:db8:ffff::7';
    contact_assert(
        brvtal_contact_client_key($config) === hash('sha256', '2001:db8:ffff::7'),
        'trusted IPv6 proxy CIDRs are supported'
    );

    $_SERVER['REMOTE_ADDR'] = '203.0.113.8';
    $_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
    contact_assert(
        brvtal_contact_client_key($config) === hash('sha256', '203.0.113.8'),
        'invalid forwarded addresses fail closed to the immediate peer'
    );
} finally {
    if ($originalRemote === null) unset($_SERVER['REMOTE_ADDR']); else $_SERVER['REMOTE_ADDR'] = $originalRemote;
    if ($originalForwarded === null) unset($_SERVER['HTTP_CF_CONNECTING_IP']); else $_SERVER['HTTP_CF_CONNECTING_IP'] = $originalForwarded;
}

$rateLimitPath = str_replace('\\', '/', brvtal_contact_rate_limit_path());
contact_assert(str_ends_with($rateLimitPath, '/storage/rate_limits/contact-rate-limit.json'), 'Contact rate-limit state lives under protected storage/rate_limits');
$storageRules = (string)file_get_contents(__DIR__ . '/../storage/.htaccess');
$rateLimitRules = (string)file_get_contents(__DIR__ . '/../storage/rate_limits/.htaccess');
contact_assert(str_contains($storageRules, 'json'), 'storage root denies legacy JSON state files');
contact_assert(str_contains($rateLimitRules, 'Require all denied'), 'rate-limit subtree denies all direct HTTP reads');

$temp = sys_get_temp_dir() . '/brvtal-contact-rate-' . bin2hex(random_bytes(5)) . '.json';
$key = hash('sha256', '127.0.0.1');
for ($i = 0; $i < 5; $i++) {
    $rate = brvtal_contact_consume_rate_limit($key, $temp, $now + $i, 5, 900);
    contact_assert($rate['allowed'] === true, 'allowed requests stay inside rate window');
}
$blocked = brvtal_contact_consume_rate_limit($key, $temp, $now + 5, 5, 900);
contact_assert($blocked['allowed'] === false && $blocked['error'] === 'RATE_LIMITED', 'sixth request is rate limited');
contact_assert((int)$blocked['retry_after'] > 0, 'rate limit exposes retry window');
@unlink($temp);

[$recipient, $mailSubject, $body, $headers] = brvtal_contact_build_mail($valid['data'], $config);
contact_assert($recipient === 'inbox@example.com', 'contact recipient comes from server-side configuration');
contact_assert($mailSubject === '[BRVTAL CONTACT] Booking inquiry', 'mail subject is scoped and sanitized');
contact_assert(str_contains($body, 'Felipe Test') && str_contains($body, 'Booking inquiry'), 'mail body contains validated contact fields');
contact_assert(str_contains($headers, 'Reply-To: felipe@example.com'), 'validated sender becomes Reply-To without exposing destination in the frontend');

$seo = brvtal_public_contact_seo('https://www.brvtal.com.co');
contact_assert($seo['canonical'] === 'https://www.brvtal.com.co/contact', 'Contact has its own canonical URL');
contact_assert(($seo['schema']['@type'] ?? '') === 'ContactPage', 'Contact emits ContactPage structured data');
$page = brvtal_public_contact_page($seo);
contact_assert(str_contains($page, '<h1 id="contactTitle" data-text="CONTACT">CONTACT</h1>'), 'dedicated Contact page has a semantic BRVTAL hero');
contact_assert(str_contains($page, 'id="brvtalContactForm"'), 'dedicated Contact page server-renders the full form');
contact_assert(str_contains($page, 'aria-label="CAPTCHA answer"'), 'CAPTCHA input exposes an accessible name');
contact_assert(str_contains($page, 'role="status" aria-live="polite"'), 'contact status is announced accessibly');
contact_assert(str_contains($page, 'href="/" aria-label="BRVTAL Home"'), 'Contact brand returns to Home');
contact_assert(str_contains($page, 'data-contact-social-mount'), 'dedicated Contact page exposes a social hydration target');

$ui = (string)file_get_contents(__DIR__ . '/../js/public-contact.js');
$styles = (string)file_get_contents(__DIR__ . '/../css/contact-social.css');
$indexPhp = (string)file_get_contents(__DIR__ . '/../index.php');
$indexHtml = (string)file_get_contents(__DIR__ . '/../index.html');
$htaccess = (string)file_get_contents(__DIR__ . '/../.htaccess');

contact_assert(str_contains($ui, "document.querySelector('[data-public-contact-page]')"), 'Contact runtime activates only on the dedicated page');
contact_assert(str_contains($ui, "['instagram','soundcloud','youtube','spotify']"), 'all supported social networks are rendered in the public rail');
contact_assert(str_contains($ui, 'data?.settings?.social'), 'social links continue to use public settings as their source of truth');
foreach (['instagram', 'soundcloud', 'youtube', 'spotify'] as $network) {
    contact_assert(str_contains($ui, $network . ": '<svg"), "{$network} has an inline SVG icon");
}
contact_assert(str_contains($ui, 'rel="noopener noreferrer"'), 'external social links use safe opener isolation');
contact_assert(str_contains($ui, 'form.reset()'), 'successful delivery clears the form only after confirmation');
contact_assert(str_contains($styles, 'body.brvtal-contact-page{'), 'Contact has a dedicated BRVTAL page surface');
contact_assert(str_contains($styles, '.brvtal-social-link{') && str_contains($styles, 'width:48px;height:48px'), 'social icons expose touch-friendly targets');
contact_assert(str_contains($styles, '@media(max-width:900px)') && str_contains($styles, '@media(max-width:560px)'), 'Contact has first-class tablet/mobile layouts');
contact_assert(str_contains($styles, '@media(prefers-reduced-motion:reduce)'), 'Contact respects reduced-motion preferences');
contact_assert(str_contains($styles, 'font:16px "Space Mono"'), 'form controls avoid small iOS input text');

contact_assert(!str_contains($indexHtml, 'id="brvtalContactForm"'), 'Home source does not contain the complete Contact form');
contact_assert(str_contains($indexPhp, "str_replace('<a href=\"#contact\"><span>07</span>CONTACT</a>', '<a href=\"/contact\"><span>07</span>CONTACT</a>'"), 'Home navigation is server-rendered to the dedicated Contact route');
contact_assert(str_contains($indexPhp, "href=\"/contact\" data-cursor=\"CONTACT\""), 'Home footer CTA routes to dedicated Contact');
contact_assert(str_contains($indexPhp, "\$pageRoute === 'contact'"), 'canonical public router recognizes Contact');
contact_assert(str_contains($htaccess, 'RewriteRule ^contact/?$ index.php?page=contact'), 'LiteSpeed direct load/refresh routes /contact server-side');
contact_assert(in_array('/contact', brvtal_public_static_routes(), true), 'canonical sitemap route registry includes Contact');
$contactSitemapRows = brvtal_public_sitemap_static_urls(
    BRVTAL_SITEMAP_CANONICAL_ORIGIN,
    brvtal_public_static_routes()
);
$contactSitemapXml = brvtal_public_sitemap_xml(
    $contactSitemapRows,
    BRVTAL_SITEMAP_CANONICAL_ORIGIN
);
contact_assert(
    str_contains($contactSitemapXml, '<loc>https://www.brvtal.com.co/contact</loc>'),
    'Contact reaches rendered sitemap XML through the executable static-route boundary'
);

echo "Public contact contract passed.\n";
