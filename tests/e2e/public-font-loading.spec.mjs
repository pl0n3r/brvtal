import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const publicEntry = readFileSync(join(process.cwd(), 'index.php'), 'utf8');
const fontHref = 'https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;500;600;700;800;900&family=Space+Mono:wght@400;700&display=swap';

function optimize(markup) {
  const php = `require 'config/public_assets.php'; echo brvtal_public_optimize_font_stylesheet(${JSON.stringify(markup)});`;
  return execFileSync('php', ['-r', php], { cwd: process.cwd(), encoding: 'utf8' });
}

test('public home converts Google Fonts stylesheet into nonblocking preload with noscript fallback', async () => {
  expect(publicEntry).toContain('brvtal_public_optimize_font_stylesheet($html)');

  const blocking = `<link href="${fontHref}" rel="stylesheet">`;
  const optimized = optimize(blocking);

  expect(optimized).toContain(`rel="preload" href="${fontHref}" as="style"`);
  expect(optimized).toContain("onload=\"this.onload=null;this.rel='stylesheet'\"");
  expect(optimized).toContain(`<noscript><link href="${fontHref}" rel="stylesheet"></noscript>`);
  expect(optimized.startsWith('<link href=')).toBe(false);
});

test('font optimization leaves unrelated or already optimized markup unchanged', async () => {
  const unrelated = '<link rel="stylesheet" href="css/style.css">';
  expect(optimize(unrelated)).toBe(unrelated);

  const preload = `<link rel="preload" href="${fontHref}" as="style">`;
  expect(optimize(preload)).toBe(preload);
});
