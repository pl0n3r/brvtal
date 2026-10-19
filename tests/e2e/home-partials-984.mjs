import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const PARTS = ["nav","hero","experience","nights","artists","sound","memories","journal","connected","footer"];

/** Compose current fixed home partials instead of testing the raw template. */
export function composeHomeHtml(root = process.cwd()) {
  let html = readFileSync(join(root, 'index.html'), 'utf8');
  for (const name of PARTS) {
    const marker = `<!-- BRVTAL-HOME-PARTIAL:${name} -->`;
    if (html.split(marker).length !== 2) {
      throw new Error(`Invalid home partial marker: ${name}`);
    }
    html = html.replace(marker, readFileSync(join(root, 'partials/home', `${name}.html`), 'utf8'));
  }
  if (html.includes('BRVTAL-HOME-PARTIAL:')) {
    throw new Error('Unexpanded home partial marker');
  }
  return html;
}
