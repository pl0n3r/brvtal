import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const studioJs = readFileSync(join(process.cwd(), 'discadmin/theme-studio-v2.js'), 'utf8');
const studioCss = readFileSync(join(process.cwd(), 'discadmin/theme-studio-v2.css'), 'utf8');
const draftsJs = readFileSync(join(process.cwd(), 'discadmin/editor-drafts.js'), 'utf8');
const harness = 'http://127.0.0.1:4173/discadmin/theme-studio-drafts-e2e.html';

const baseTheme = {
  name:'CUSTOM CORE', slug:'core',
  branding:{siteName:'CUSTOM BRVTAL',tagline:'CUSTOM TAGLINE',logo:'',mobileLogo:'',favicon:'',preloaderLogo:''},
  colors:{bg:'#111111',surface:'#151515',text:'#eeeeee',muted:'#888888',primary:'#aa0000',accent:'#00ff88',border:'#333333'},
  typography:{display:'Arial, sans-serif',body:'Arial, sans-serif',mono:'monospace',h1:'80px',bodySize:'16px',tracking:'-0.02em'},
  navigation:{fixed:true,transparentHero:true,blur:true,menuStyle:'fullscreen',logoPosition:'left',sceneIndicator:true,soundToggle:true},
  effects:{grain:true,scanlines:true,glitch:true,cursor:true,magnetic:true,motion:'subtle'},
  sound:{enabled:true},
  seo:{siteTitle:'LEGACY SEO',description:'KEEP ME',ogImage:'/legacy-og.png'}
};

async function openStudio(page, { failSave=false, holdSave=false, holdReload=false } = {}) {
  await page.route('https://fonts.googleapis.com/**', route => route.fulfill({status:200,contentType:'text/css',body:''}));
  await page.route(harness, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:`<!doctype html><html><head><meta charset="utf-8"><style>${studioCss}</style></head><body><main><div id="theme-root"></div></main></body></html>`
  }));
  await page.goto(harness);

  await page.evaluate(({ baseTheme, failSave, holdSave, holdReload }) => {
    window.csrf = 'theme-draft-session';
    window.THEME_DEFAULT = JSON.parse(JSON.stringify(baseTheme));
    const altTheme = JSON.parse(JSON.stringify(baseTheme));
    altTheme.name = 'ALT THEME';
    altTheme.slug = 'alt';
    altTheme.branding.siteName = 'ALT BRVTAL';
    window.state = { theme:null, themeSettings:[
      {setting_key:'theme.active',setting_value:'core',is_json:0},
      {setting_key:'theme.core',setting_value:JSON.stringify(baseTheme),is_json:1},
      {setting_key:'theme.alt',setting_value:JSON.stringify(altTheme),is_json:1}
    ], themeMedia:[] };
    window.deepMergeTheme = (base,extra) => {
      const out = JSON.parse(JSON.stringify(base || {}));
      const merge = (left,right) => Object.keys(right || {}).forEach(key => {
        if (key === '__proto__' || key === 'constructor' || key === 'prototype') return;
        if (right[key] && typeof right[key] === 'object' && !Array.isArray(right[key]) && left[key] && typeof left[key] === 'object') merge(left[key],right[key]);
        else Object.defineProperty(left, key, {value:right[key], writable:true, enumerable:true, configurable:true});
      });
      merge(out,extra || {});
      return out;
    };
    window.__themeSavePayloads = [];
    window.__themeActivatePayloads = [];
    window.__themeSaveStarted = 0;
    window.__themeFailSave = failSave;
    window.__themeHoldSave = holdSave;
    window.__releaseThemeSave = null;
    window.__themeHoldReload = holdReload;
    window.__themeReloadStarted = 0;
    window.__releaseThemeReload = null;
    window.BRVTALFeedback = {progress(){},success(){},error(){}};
    window.req = async (path, options={}) => {
      const method = String(options.method || 'GET').toUpperCase();
      if (method === 'GET' && path === '/settings') {
        if (window.__themeHoldReload && window.__themeSavePayloads.length > 0) {
          window.__themeReloadStarted += 1;
          await new Promise(resolve => { window.__releaseThemeReload = resolve; });
          window.__themeHoldReload = false;
        }
        return {data:JSON.parse(JSON.stringify(window.state.themeSettings))};
      }
      if (method === 'GET' && path === '/media') return {data:[]};
      if (method === 'POST' && path === '/settings') {
        const payload = JSON.parse(options.body);
        if (payload.setting_key === 'theme.active') {
          window.__themeActivatePayloads.push(payload);
          const row = window.state.themeSettings.find(item => item.setting_key === 'theme.active');
          row.setting_value = payload.setting_value;
          return {data:[]};
        }
        window.__themeSaveStarted += 1;
        if (window.__themeHoldSave) {
          await new Promise(resolve => { window.__releaseThemeSave = resolve; });
        }
        if (window.__themeFailSave) throw new Error('THEME_SAVE_FAILED');
        window.__themeSavePayloads.push(payload);
        const existing = window.state.themeSettings.find(item => item.setting_key === payload.setting_key);
        if (existing) existing.setting_value = payload.setting_value;
        else window.state.themeSettings.push({setting_key:payload.setting_key,setting_value:payload.setting_value,is_json:1});
        return {data:[]};
      }
      throw new Error('unexpected request ' + method + ' ' + path);
    };
  }, { baseTheme, failSave, holdSave, holdReload });

  await page.addScriptTag({content:draftsJs});
  await page.addScriptTag({content:studioJs});
  await page.evaluate(() => window.BRVTALThemeStudioV2.load('core'));
  await expect(page.locator('[data-theme-studio-v2]')).toBeVisible();
}

test('Theme Studio autosaves locally and restores without server mutation or activation', async ({page}) => {
  await openStudio(page);
  await page.locator('#th_siteName').fill('LOCAL THEME');
  await expect(page.locator('[data-theme-draft-state]')).toHaveText('Draft saved locally');
  expect(await page.evaluate(() => window.__themeSaveStarted)).toBe(0);
  expect(await page.evaluate(() => window.__themeSavePayloads.length)).toBe(0);
  expect(await page.evaluate(() => window.__themeActivatePayloads.length)).toBe(0);

  await page.evaluate(() => window.BRVTALThemeStudioV2.load('core'));
  await expect(page.locator('[data-theme-draft-recovery]')).toBeVisible();
  await expect(page.locator('#th_siteName')).toHaveValue('CUSTOM BRVTAL');
  await page.locator('[data-theme-draft-restore]').click();
  await expect(page.locator('#th_siteName')).toHaveValue('LOCAL THEME');
  expect(await page.evaluate(() => window.__themeActivatePayloads.length)).toBe(0);
});

test('Theme Studio warns when the server setting changed after the local draft', async ({page}) => {
  await openStudio(page);
  await page.locator('#th_siteName').fill('LOCAL CONFLICT');
  await expect(page.locator('[data-theme-draft-state]')).toHaveText('Draft saved locally');
  await page.evaluate(() => {
    const row = window.state.themeSettings.find(item => item.setting_key === 'theme.core');
    const changed = JSON.parse(row.setting_value);
    changed.branding.siteName = 'SERVER CHANGED';
    row.setting_value = JSON.stringify(changed);
  });
  await page.evaluate(() => window.BRVTALThemeStudioV2.load('core'));
  const recovery = page.locator('[data-theme-draft-recovery]');
  await expect(recovery).toHaveAttribute('data-conflict','1');
  await expect(recovery).toContainText('SERVER CHANGED');
});

test('Theme Studio keeps local recovery when Save Draft fails', async ({page}) => {
  await openStudio(page, {failSave:true});
  await page.locator('#th_siteName').fill('RETRY THEME');
  await expect(page.locator('[data-theme-draft-state]')).toHaveText('Draft saved locally');
  await page.getByRole('button',{name:'SAVE DRAFT'}).click();
  await expect.poll(() => page.evaluate(() => window.__themeSaveStarted)).toBe(1);
  await expect(page.locator('[data-theme-draft-state]')).toContainText('Save failed');
  expect(await page.evaluate(async () => (await BRVTALDrafts.load('theme-studio','core'))?.data?.branding?.siteName)).toBe('RETRY THEME');
  expect(await page.evaluate(() => window.__themeActivatePayloads.length)).toBe(0);
});

test('Theme Studio preserves edits made while Save Draft is in flight', async ({page}) => {
  await openStudio(page, {holdSave:true});
  await page.locator('#th_siteName').fill('SUBMITTED THEME');
  await expect(page.locator('[data-theme-draft-state]')).toHaveText('Draft saved locally');

  await page.getByRole('button',{name:'SAVE DRAFT'}).click();
  await expect.poll(() => page.evaluate(() => window.__themeSaveStarted)).toBe(1);
  await page.locator('#th_siteName').fill('NEWER LOCAL THEME');
  await page.evaluate(() => window.__releaseThemeSave?.());
  await expect.poll(() => page.evaluate(() => window.__themeSavePayloads.length)).toBe(1);

  await expect(page.locator('#th_siteName')).toHaveValue('NEWER LOCAL THEME');
  await expect(page.locator('[data-theme-draft-state]')).toContainText('newer edits remain unsaved');
  expect(await page.evaluate(() => JSON.parse(window.__themeSavePayloads[0].setting_value).branding.siteName)).toBe('SUBMITTED THEME');
  expect(await page.evaluate(async () => (await BRVTALDrafts.load('theme-studio','core'))?.data?.branding?.siteName)).toBe('NEWER LOCAL THEME');
  expect(await page.evaluate(() => window.__themeActivatePayloads.length)).toBe(0);
});


test('Theme Studio preserves recovery under a slug changed during Save Draft', async ({page}) => {
  await openStudio(page, {holdSave:true});
  await page.locator('#th_siteName').fill('SUBMITTED THEME');
  await page.getByRole('button',{name:'SAVE DRAFT'}).click();
  await expect.poll(() => page.evaluate(() => window.__themeSaveStarted)).toBe(1);

  await page.locator('#th_slug').fill('new-theme');
  await page.evaluate(() => window.__releaseThemeSave?.());
  await expect.poll(() => page.evaluate(() => window.__themeSavePayloads.length)).toBe(1);

  await expect(page.locator('#th_slug')).toHaveValue('new-theme');
  expect(await page.evaluate(async () => (await BRVTALDrafts.load('theme-studio','new-theme'))?.data?.slug)).toBe('new-theme');
  expect(await page.evaluate(async () => BRVTALDrafts.load('theme-studio','core'))).toBe(null);
  expect(await page.evaluate(() => window.__themeActivatePayloads.length)).toBe(0);
});

test('Theme Studio Restore never activates while explicit Save & Activate still does', async ({page}) => {
  await openStudio(page);
  await page.locator('#th_tagline').fill('LOCAL TAGLINE');
  await expect(page.locator('[data-theme-draft-state]')).toHaveText('Draft saved locally');
  await page.evaluate(() => window.BRVTALThemeStudioV2.load('core'));
  await page.locator('[data-theme-draft-restore]').click();
  expect(await page.evaluate(() => window.__themeActivatePayloads.length)).toBe(0);

  await page.getByRole('button',{name:'SAVE & ACTIVATE'}).click();
  await expect.poll(() => page.evaluate(() => window.__themeActivatePayloads.length)).toBe(1);
  expect(await page.evaluate(() => window.state.themeSettings.find(item => item.setting_key === 'theme.active').setting_value)).toBe('core');
});

test('Theme Studio local recovery is cleared at the auth session boundary', async ({page}) => {
  await openStudio(page);
  await page.locator('#th_siteName').fill('SESSION THEME');
  await expect(page.locator('[data-theme-draft-state]')).toHaveText('Draft saved locally');
  await page.evaluate(() => window.dispatchEvent(new CustomEvent('brvtal:auth-required')));
  await expect(page.locator('[data-theme-draft-recovery]')).toHaveCount(0);
  await expect(page.locator('#th_siteName')).toHaveValue('CUSTOM BRVTAL');
  await expect.poll(() => page.evaluate(() => BRVTALDrafts.load('theme-studio','core'))).toBe(null);
});


test('Theme Studio preserves a pending recovery draft while the user edits before Restore or Discard', async ({page}) => {
  await openStudio(page);
  await page.locator('#th_siteName').fill('ORIGINAL RECOVERY');
  await expect(page.locator('[data-theme-draft-state]')).toHaveText('Draft saved locally');

  await page.evaluate(() => {
    const row = window.state.themeSettings.find(item => item.setting_key === 'theme.core');
    const changed = JSON.parse(row.setting_value);
    changed.branding.siteName = 'SERVER AFTER RECOVERY';
    row.setting_value = JSON.stringify(changed);
  });
  await page.evaluate(() => window.BRVTALThemeStudioV2.load('core'));
  await expect(page.locator('[data-theme-draft-recovery]')).toHaveAttribute('data-conflict','1');

  await page.locator('#th_siteName').fill('SERVER EDIT WHILE RECOVERY PENDING');
  await page.waitForTimeout(750);
  expect(await page.evaluate(async () => (await BRVTALDrafts.load('theme-studio','core'))?.data?.branding?.siteName))
    .toBe('ORIGINAL RECOVERY');

  await page.evaluate(() => window.BRVTALThemeStudioV2.load('core'));
  await expect(page.locator('[data-theme-draft-recovery]')).toBeVisible();
  await page.locator('[data-theme-draft-restore]').click();
  await expect(page.locator('#th_siteName')).toHaveValue('ORIGINAL RECOVERY');
});

test('Theme Studio preserves edits made during the post-save reload', async ({page}) => {
  await openStudio(page, {holdReload:true});
  await page.locator('#th_siteName').fill('SUBMITTED BEFORE RELOAD');
  await expect(page.locator('[data-theme-draft-state]')).toHaveText('Draft saved locally');

  await page.getByRole('button',{name:'SAVE DRAFT'}).click();
  await expect.poll(() => page.evaluate(() => window.__themeReloadStarted)).toBe(1);

  await page.locator('#th_siteName').fill('EDIT DURING RELOAD');
  await page.evaluate(() => window.__releaseThemeReload?.());

  await expect(page.locator('#th_siteName')).toHaveValue('EDIT DURING RELOAD');
  await expect(page.locator('[data-theme-draft-state]')).toContainText('newer edits remain unsaved');
  expect(await page.evaluate(async () => (await BRVTALDrafts.load('theme-studio','core'))?.data?.branding?.siteName))
    .toBe('EDIT DURING RELOAD');
});

test('Theme Studio ignores a stale async theme switch when the user returns to the current theme', async ({page}) => {
  await openStudio(page);
  await page.evaluate(() => {
    const originalLoad = BRVTALDrafts.load.bind(BRVTALDrafts);
    window.__altDraftLoadStarted = 0;
    window.__releaseAltDraftLoad = null;
    BRVTALDrafts.load = async (scope, id) => {
      if (scope === 'theme-studio' && id === 'alt') {
        window.__altDraftLoadStarted += 1;
        await new Promise(resolve => { window.__releaseAltDraftLoad = resolve; });
      }
      return originalLoad(scope, id);
    };
  });

  await page.locator('#tsv2-theme-select').selectOption('alt');
  await expect.poll(() => page.evaluate(() => window.__altDraftLoadStarted)).toBe(1);
  await page.locator('#tsv2-theme-select').selectOption('core');
  await page.evaluate(() => window.__releaseAltDraftLoad?.());

  await expect(page.locator('#th_siteName')).toHaveValue('CUSTOM BRVTAL');
  await expect(page.locator('#tsv2-theme-select')).toHaveValue('core');
});

test('Theme Studio cancels an async recovery continuation after auth expires', async ({page}) => {
  await openStudio(page);
  await page.locator('#th_siteName').fill('AUTH RECOVERY');
  await expect(page.locator('[data-theme-draft-state]')).toHaveText('Draft saved locally');

  await page.evaluate(() => {
    const originalLoad = BRVTALDrafts.load.bind(BRVTALDrafts);
    window.__authDraftLoadStarted = 0;
    window.__releaseAuthDraftLoad = null;
    BRVTALDrafts.load = async (scope, id) => {
      if (scope === 'theme-studio' && id === 'core') {
        window.__authDraftLoadStarted += 1;
        await new Promise(resolve => { window.__releaseAuthDraftLoad = resolve; });
      }
      return originalLoad(scope, id);
    };
    window.__pendingThemeLoad = BRVTALThemeStudioV2.load('core');
  });

  await expect.poll(() => page.evaluate(() => window.__authDraftLoadStarted)).toBe(1);
  await page.evaluate(() => {
    window.dispatchEvent(new CustomEvent('brvtal:auth-required'));
    document.getElementById('theme-root').innerHTML = '<div data-auth-cleared>AUTH CLEARED</div>';
    window.__releaseAuthDraftLoad?.();
  });
  await page.evaluate(() => window.__pendingThemeLoad);

  await expect(page.locator('[data-auth-cleared]')).toHaveText('AUTH CLEARED');
  await expect(page.locator('[data-theme-studio-v2]')).toHaveCount(0);
});
