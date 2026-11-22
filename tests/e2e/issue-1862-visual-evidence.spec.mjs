import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const root = process.cwd();
const output = join(root, 'artifacts', 'issue-1862-visual');
const css = [
  '*{box-sizing:border-box}',
  'html,body{margin:0;background:#0b0a09;color:#f1ede5;font:14px/1.6 Arial,Helvetica,sans-serif}',
  '.qa-banner{padding:8px 12px;text-align:center;background:#302019;color:#f2d0b2;font-size:11px;letter-spacing:.04em}',
  '.cp-top{display:flex;align-items:center;gap:24px;padding:16px 4vw;border-bottom:1px solid #36322d}',
  '.entity-brand{color:#f9f5ee;text-decoration:none;font-size:24px;font-weight:800;letter-spacing:.1em}',
  '.entity-brand small{display:block;font-size:9px;font-weight:400;letter-spacing:.18em}',
  '.cp-nav{display:flex;gap:16px;flex:1}.cp-nav a,.cp-back{color:#f1ede5;text-decoration:none;font-size:11px}',
  '.cp-tools{display:flex;gap:14px}.cp-snd{display:none}',
  'main{max-width:54rem;margin:2rem auto!important;padding:1.5rem!important}',
  'h1{font:normal clamp(40px,5vw,66px)/1.08 Georgia,serif;letter-spacing:-.03em;margin:15px 0 21px}',
  'h2{font-size:23px;margin-top:24px}h3{font-size:13px;letter-spacing:.03em;margin-bottom:2px}',
  'section{border-top:1px solid #454039;margin-top:24px;padding-top:14px}',
  'p{color:#d0cac0;max-width:65ch}a{color:#f2e8db;text-underline-offset:3px}',
  '[data-privacy-contact-update]{background:#201d18;border:1px solid #514840;padding:15px}',
  '.qa-placeholder{display:inline-block;border:1px solid #87433d;background:#2f1714;color:#ffe2cc;padding:4px 9px}',
  '.qa-old-legal{border:1px solid #854b3b;color:#f2bca5;padding:13px}',
  '.qa-cookie{position:fixed;bottom:0;left:0;right:0;display:flex;align-items:center;justify-content:space-between;gap:18px;padding:14px 4vw;background:#191613;border-top:1px solid #6d4840}',
  '.qa-cookie p{margin:0;font-size:12px}.qa-actions{display:flex;gap:10px}',
  '.qa-actions button{min-height:48px;padding:9px 16px;background:transparent;border:1px solid #d6ccc0;color:#f6f1e9}',
  '.qa-actions button:last-child{background:#a62e2a;border-color:#b83c35}',
  'footer{border-top:1px solid #34302a;padding:15px 4vw;font-size:12px}',
  '@media(max-width:600px){.cp-top{padding:14px 20px}.entity-brand{font-size:18px}',
  '.cp-nav,.cp-tools .cp-back:first-of-type{display:none}.cp-tools{margin-left:auto}',
  'main{margin:1rem auto!important;padding:16px 20px 190px!important}',
  'h1{font-size:40px}h2{font-size:20px}.qa-cookie{padding:13px 20px;display:block}',
  '.qa-cookie p{font-size:11px;margin-bottom:10px}.qa-actions{display:grid;grid-template-columns:1fr 1fr}}'
].join('\n');

function render(html, state) {
  // Render original PHP markup unchanged. A restrictive, early CSP blocks all
  // JavaScript and network fetches in this isolated, synthetic visual fixture.
  let result = html;
  if (state === 'before') {
    const labels = ['Responsable del tratamiento', 'Correo de contacto del responsable',
      'Teléfono público del responsable', 'Domicilio del responsable',
      'Dirección de contacto', 'Plazo para consultas', 'Plazo de respuesta'];
    const placeholders = labels.map(label => '<h3>' + label + '</h3><p class="qa-placeholder">' +
      '[COMPLETAR POR EL DUEÑO]</p>').join('');
    result = result.replace(/<p role="note" data-privacy-contact-update>[\s\S]*?<\/p>/,
      placeholders);
    result = result.replace('</main>', '<p class="qa-old-legal">' +
      'Texto legal pendiente de aprobación profesional (Condor #974).</p></main>');
  }
  const title = state === 'after' ? 'DESPUÉS' : 'ANTES';
  const copy = state === 'after'
    ? 'Usamos cookies opcionales solo si las aceptas. Puedes rechazar y cambiar tu elección.'
    : 'Texto legal pendiente de aprobación profesional. Selecciona tus cookies.';
  const cookie = '<aside class="qa-cookie" aria-label="Demo sintética de cookies"><p>' +
    copy + '</p><div class="qa-actions"><button>RECHAZAR</button><button>ACEPTAR</button></div></aside>';
  const csp = "<meta http-equiv=\"Content-Security-Policy\" content=\"" +
    "default-src 'none'; script-src 'none'; connect-src 'none'; " +
    "img-src 'none'; font-src 'none'; style-src 'unsafe-inline';\">";
  result = result.replace('</head>', csp + '<style>' + css + '</style></head>');
  result = result.replace(/(<body\b[^>]*>)/, '$1<div class="qa-banner">' +
    'CAPTURA LOCAL DE FIXTURE SINTÉTICA · NO ES PRODUCCIÓN · ' + title +
    ' · BRVTAL #1862</div>');
  return result.replace('</body>', cookie + '</body>');
}

test('AC-02: cuatro capturas reales de Chromium con fixture sintética a 390 y 1440', async ({ page }) => {
  const php = execFileSync('php', ['-r',
    "require 'config/public_privacy_rights.php'; echo brvtalPublicPrivacyRightsDocument([]);"],
    { cwd: root, encoding: 'utf8', timeout: 20000 });
  expect(php).toContain('data-privacy-contact-update');
  expect(php).not.toContain('[COMPLETAR POR EL DUEÑO]');
  mkdirSync(output, { recursive: true });
  const generated = [];
  for (const width of [390, 1440]) {
    for (const state of ['before', 'after']) {
      await page.setViewportSize({ width, height: 900 });
      await page.setContent(render(php, state), { waitUntil: 'load' });
      await expect(page.locator('meta[http-equiv="Content-Security-Policy"]'))
        .toHaveAttribute('content', /script-src 'none'/);
      await expect(page.getByRole('heading', { name: 'Privacidad y mis derechos', exact: true })).toBeVisible();
      expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
      const content = await page.locator('body').innerText();
      if (state === 'before') expect(content).toContain('[COMPLETAR POR EL DUEÑO]');
      else expect(content).not.toContain('[COMPLETAR POR EL DUEÑO]');
      const file = state + '-' + width + '.png';
      await page.screenshot({ path: join(output, file), fullPage: false });
      generated.push({ file, width, height: 900, state });
    }
  }
  const head = execFileSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).trim();
  writeFileSync(join(output, 'manifest.json'), JSON.stringify({
    issue: 1862, pr: 1878, head, browser: 'Playwright Chromium',
    source_after: 'local PHP render config/public_privacy_rights.php with empty synthetic settings',
    source_before: 'synthetic reconstruction of previous placeholder behavior',
    production_observed: false, legally_approved: false, generated
  }, null, 2) + '\n');
});
