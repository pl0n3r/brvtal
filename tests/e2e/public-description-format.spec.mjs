import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';

const source = String.raw`
require_once 'config/public_seo.php';
require_once 'config/public_page.php';
$route = $argv[1];
$raw = $route === 'artists' ? '**DNL5 no sacrifica el baile**' : '# GENESIS — EL REGRESO';
$page = ['entity'=>['id'=>2,'route_type'=>$route,'title'=>$route==='artists'?'DNL5':'Génesis','description'=>$raw,'status'=>'published'],
 'facts'=>[], 'links'=>[], 'related'=>[], 'record'=>['state'=>'active'], 'degraded'=>false];
$seo = ['title'=>'BRVTAL','description'=>$raw,'image'=>'','canonical'=>'https://www.brvtal.com.co/'.$route.'/test',
 'schema'=>['@context'=>'https://schema.org','@type'=>'WebPage','description'=>$raw]];
echo brvtal_public_entity_page($page,$seo);
`;
function render(route) {
  return execFileSync('php', ['-r',source,route], {cwd:process.cwd(),encoding:'utf8'});
}
test('real public artist bio renders bold and stripped plain SEO', async ({page}) => {
  await page.setContent(render('artists'));
  await expect(page.locator('.entity-statement strong')).toHaveText('DNL5 no sacrifica el baile');
  expect(await page.locator('.entity-statement').innerText()).not.toContain('**');
  expect(await page.locator('meta[name=description]').getAttribute('content')).not.toMatch(/\*\*|#/);
  expect(await page.locator('script[type="application/ld+json"]').textContent()).not.toMatch(/\*\*|# GENESIS/);
});
test('real event description renders a heading, never a second h1 or markup in metadata', async ({page}) => {
  await page.setContent(render('events'));
  await expect(page.locator('.entity-statement h2')).toHaveText('GENESIS — EL REGRESO');
  await expect(page.locator('h1')).toHaveCount(1);
  expect(await page.locator('meta[name=description]').getAttribute('content')).not.toContain('#');
  expect(await page.locator('script[type="application/ld+json"]').textContent()).not.toContain('# GENESIS');
});
