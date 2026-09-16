import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const runtime = readFileSync(join(process.cwd(), 'js/public-contact.js'), 'utf8');
const styles = readFileSync(join(process.cwd(), 'css/contact-social.css'), 'utf8');

const markup = `<!doctype html><html><head><style>${styles}</style></head><body class="brvtal-contact-page" data-public-contact-page>
  <header class="contact-page-nav"><a class="contact-page-brand" href="/"><strong>BRVTAL</strong><span>RAVE TILL GRAVE</span></a><a class="contact-page-home mono" href="/">HOME ↙</a></header>
  <main class="contact-page-main">
    <section class="contact-page-hero"><h1>CONTACT</h1></section>
    <section class="contact-page-workspace">
      <aside class="contact-page-context"><div data-contact-social-mount></div></aside>
      <div class="brvtal-contact-shell" id="contactForm">
        <form class="brvtal-contact-form" id="brvtalContactForm" novalidate>
          <div class="brvtal-contact-field"><label for="contactName">NAME</label><input id="contactName" name="name" required><span data-error-for="name"></span></div>
          <div class="brvtal-contact-field"><label for="contactEmail">EMAIL</label><input id="contactEmail" name="email" type="email" required><span data-error-for="email"></span></div>
          <div class="brvtal-contact-field"><label for="contactSubject">SUBJECT</label><input id="contactSubject" name="subject" required><span data-error-for="subject"></span></div>
          <div class="brvtal-contact-field"><label for="contactMessage">MESSAGE</label><textarea id="contactMessage" name="message" required></textarea><span data-error-for="message"></span></div>
          <input name="website" value=""><div class="brvtal-captcha"><div class="brvtal-captcha-copy"><strong data-contact-captcha-question>LOADING…</strong></div><div><input class="brvtal-captcha-input" name="captcha_answer" aria-label="CAPTCHA answer"><span data-error-for="captcha"></span></div></div>
          <input type="hidden" name="captcha_token" data-contact-captcha-token>
          <div class="brvtal-contact-actions"><button class="brvtal-contact-submit" type="submit">SEND SIGNAL ↗</button><div class="brvtal-contact-status" role="status" aria-live="polite" data-contact-status>READY</div></div>
        </form>
      </div>
    </section>
  </main>
</body></html>`;

async function installContactRoutes(page) {
  await page.route('**/api/contact.php', async route => {
    const request = route.request();
    if (request.method() === 'GET') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok:true, data:{ question:'4 + 5 = ?', token:'signed.test' } }) });
      return;
    }
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok:true, message:'MESSAGE_SENT' }) });
  });
  await page.route('**/api/public.php', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ data:{ settings:{ social:{ instagram:'https://instagram.com/brvtal', soundcloud:'https://soundcloud.com/brvtal' } } } }),
  }));
}

test('dedicated Contact runtime hydrates CAPTCHA and configured social links', async ({ page }) => {
  await installContactRoutes(page);
  await page.setContent(markup, { waitUntil:'domcontentloaded' });
  await page.addScriptTag({ content: runtime });

  await expect(page.locator('[data-contact-captcha-question]')).toHaveText('4 + 5 = ?');
  await expect(page.locator('[data-contact-captcha-token]')).toHaveValue('signed.test');
  await expect(page.locator('[data-brvtal-social="instagram"]')).toHaveAttribute('href', 'https://instagram.com/brvtal');
  await expect(page.locator('[data-brvtal-social="soundcloud"]')).toHaveAttribute('href', 'https://soundcloud.com/brvtal');
  await expect(page.locator('[data-brvtal-social="youtube"]')).toBeHidden();
  await expect(page.locator('[data-brvtal-social="spotify"]')).toBeHidden();
});

test('Contact runtime does not inject a form into Home-like pages', async ({ page }) => {
  await page.setContent('<!doctype html><html><body><footer id="contact"><div class="footer-main"></div></footer></body></html>');
  await page.addScriptTag({ content: runtime });
  await expect(page.locator('#brvtalContactForm')).toHaveCount(0);
  await expect(page.locator('.brvtal-social-rail')).toHaveCount(0);
});

test('Contact is keyboard-usable on desktop', async ({ page }) => {
  await installContactRoutes(page);
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.setContent(markup, { waitUntil:'domcontentloaded' });
  await page.addScriptTag({ content: runtime });

  await page.locator('#contactName').focus();
  await expect(page.locator('#contactName')).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.locator('#contactEmail')).toBeFocused();
  await expect(page.locator('#brvtalContactForm')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});

test('Contact stays readable and touch-safe on mobile', async ({ page }) => {
  await installContactRoutes(page);
  await page.setViewportSize({ width: 390, height: 844 });
  await page.setContent(markup, { waitUntil:'domcontentloaded' });
  await page.addScriptTag({ content: runtime });

  await expect(page.locator('#brvtalContactForm')).toBeVisible();
  const submitBox = await page.locator('.brvtal-contact-submit').boundingBox();
  expect(submitBox?.height || 0).toBeGreaterThanOrEqual(44);
  const socialBox = await page.locator('[data-brvtal-social="instagram"]').boundingBox();
  expect(socialBox?.width || 0).toBeGreaterThanOrEqual(44);
  expect(socialBox?.height || 0).toBeGreaterThanOrEqual(44);
  expect(await page.locator('#contactEmail').evaluate(el => parseFloat(getComputedStyle(el).fontSize))).toBeGreaterThanOrEqual(16);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});
