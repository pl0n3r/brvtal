import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const script = readFileSync(join(process.cwd(), 'js/public-contact.js'), 'utf8');
const css = readFileSync(join(process.cwd(), 'css/contact-social.css'), 'utf8');

const pageHtml = `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>
:root{--bg:#050505;--fg:#f2f2ef;--line:rgba(255,255,255,.14);--red:#9e0d12;--acid:#b8ff00}.mono{font-family:monospace}.footer{padding:40px;background:#050505;color:#f2f2ef}.footer-main h2{font-size:70px}.footer-bottom{display:flex;justify-content:space-between}.footer-cta{display:flex;gap:20px}
${css}
</style><link id="brvtal-contact-social-style" rel="stylesheet" href="data:text/css,"></head><body><footer class="footer" id="contact"><div class="footer-main"><span class="mono">BRVTAL / PEREIRA / COLOMBIA</span><h2>RAVE<br><em>TILL GRAVE</em></h2><div class="footer-cta"><a href="mailto:contact@brvtal.com.co">CONTACT</a></div></div><div class="footer-bottom mono"><span>© 2026 BRVTAL</span><span>INSTAGRAM / SOUNDCLOUD / YOUTUBE</span><span>EN</span></div></footer></body></html>`;

function mockPublicData() {
  return {
    data: {
      settings: {
        social: {
          instagram: 'https://instagram.com/brvtal',
          soundcloud: 'https://soundcloud.com/brvtal',
          youtube: 'https://youtube.com/@brvtal',
          spotify: 'https://open.spotify.com/artist/example'
        }
      }
    }
  };
}

test.beforeEach(async ({ page }) => {
  await page.route('https://www.brvtal.com.co/api/public.php', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify(mockPublicData())
  }));
  await page.route('https://www.brvtal.com.co/api/contact.php', async route => {
    if (route.request().method() === 'GET') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok:true, data:{ question:'4 + 5 = ?', token:'signed-token', expires_in:600 } }) });
      return;
    }
    const payload = route.request().postDataJSON();
    if (payload.captcha_answer !== '9') {
      await route.fulfill({ status: 422, contentType:'application/json', body:JSON.stringify({ ok:false, error:'VALIDATION_FAILED', fields:{ captcha:'INVALID_CAPTCHA' } }) });
      return;
    }
    await route.fulfill({ status: 200, contentType:'application/json', body:JSON.stringify({ ok:true, message:'MESSAGE_SENT' }) });
  });

  await page.setContent(pageHtml, { waitUntil:'domcontentloaded' });
  await page.evaluate(() => {
    const base = document.createElement('base');
    base.href = 'https://www.brvtal.com.co/';
    document.head.prepend(base);
  });
  await page.addScriptTag({ content: script });
});

test('social settings render as accessible visual icons', async ({ page }) => {
  await expect(page.locator('.brvtal-social-rail')).toBeVisible();
  const links = page.locator('.brvtal-social-link:not([hidden])');
  await expect(links).toHaveCount(4);
  await expect(page.locator('[data-brvtal-social="instagram"]')).toHaveAttribute('href', 'https://instagram.com/brvtal');
  await expect(page.locator('[data-brvtal-social="spotify"]')).toHaveAttribute('aria-label', 'Spotify');
  await expect(page.locator('.footer-bottom > span:nth-child(2)')).toBeHidden();
});

test('contact form keeps content on captcha failure and confirms success', async ({ page }) => {
  await expect(page.locator('[data-contact-captcha-question]')).toHaveText('4 + 5 = ?');
  await page.getByLabel('NAME').fill('Felipe');
  await page.getByLabel('EMAIL').fill('felipe@example.com');
  await page.getByLabel('SUBJECT').fill('Booking');
  await page.getByLabel('MESSAGE').fill('This is a complete booking request for BRVTAL.');
  await page.getByLabel('CAPTCHA answer').fill('8');
  await page.getByRole('button', { name:'SEND SIGNAL ↗' }).click();

  await expect(page.locator('[data-error-for="captcha"]')).toContainText('CAPTCHA');
  await expect(page.getByLabel('MESSAGE')).toHaveValue('This is a complete booking request for BRVTAL.');
  await expect(page.locator('[data-contact-captcha-question]')).toHaveText('4 + 5 = ?');

  await page.getByLabel('CAPTCHA answer').fill('9');
  await page.getByRole('button', { name:'SEND SIGNAL ↗' }).click();
  await expect(page.locator('[data-contact-status]')).toContainText('MESSAGE SENT');
  await expect(page.getByLabel('MESSAGE')).toHaveValue('');
});

test('contact controls remain touch friendly on mobile', async ({ page }) => {
  await page.setViewportSize({ width:390, height:844 });
  await expect.poll(() => page.getByLabel('EMAIL').evaluate(el => parseFloat(getComputedStyle(el).fontSize))).toBeGreaterThanOrEqual(16);
  await expect.poll(() => page.getByRole('button', { name:'SEND SIGNAL ↗' }).evaluate(el => el.getBoundingClientRect().height)).toBeGreaterThanOrEqual(44);
  await expect.poll(() => page.locator('[data-brvtal-social="instagram"]').evaluate(el => el.getBoundingClientRect().height)).toBeGreaterThanOrEqual(44);
});
