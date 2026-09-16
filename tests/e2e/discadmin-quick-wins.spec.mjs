import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const reliability = readFileSync(join(process.cwd(), 'discadmin/admin-reliability.js'), 'utf8');
const routeAliases = readFileSync(join(process.cwd(), 'discadmin/admin-route-aliases.js'), 'utf8');

async function mountReliability(page) {
  await page.setContent(`<!doctype html><html><body><div id="root"></div><script>
    var csrf = 'csrf-token';
    var state = {authed:true,rows:[{setting_key:'existing.key'}],artists:[],events:[]};
    var renderCount = 0;
    var alerts = [];
    var logoutMode = 'success';
    function render(){ renderCount += 1; }
    window.alert = message => alerts.push(message);
    window.req = async function(path, options = {}) {
      if (path === '/artists') return {data:[{id:7,name:'Artist Seven'}]};
      if (path === '/events') return {data:[{id:9,title:'Event Nine'}]};
      if (path === '/auth' && options.method === 'DELETE') {
        if (logoutMode === 'fail') throw new Error('SERVER_ERROR');
        return {ok:true};
      }
      return {ok:true};
    };
    window.go = async section => { window.lastNativeGo = section; return section; };
    window.eventForm = record => record;
    window.login = async () => {};
    window.openModal = function(type,id){
      window.openedModal = {
        type,
        id,
        artists:[...state.artists],
        events:[...state.events]
      };
      return window.openedModal;
    };
    window.openSettingByKey = function(key){
      document.getElementById('f_setting_key')?.remove();
      const input = document.createElement('input');
      input.id = 'f_setting_key';
      input.value = key;
      document.body.appendChild(input);
      return input;
    };
  </script></body></html>`);
  await page.addScriptTag({content: reliability});
}

test('direct Set editor entry hydrates Artist and Event references', async ({ page }) => {
  await mountReliability(page);
  const opened = await page.evaluate(async () => window.openModal('sets'));
  expect(opened.type).toBe('sets');
  expect(opened.artists).toEqual([{id:7,name:'Artist Seven'}]);
  expect(opened.events).toEqual([{id:9,title:'Event Nine'}]);
});

test('failed logout keeps the local admin session visible until server logout is confirmed', async ({ page }) => {
  await mountReliability(page);
  const failed = await page.evaluate(async () => {
    logoutMode = 'fail';
    await window.logout();
    return {authed:state.authed,csrf,renderCount,alerts:[...alerts]};
  });
  expect(failed.authed).toBe(true);
  expect(failed.csrf).toBe('csrf-token');
  expect(failed.renderCount).toBe(0);
  expect(failed.alerts.at(-1)).toContain('LOGOUT COULD NOT BE CONFIRMED');

  const succeeded = await page.evaluate(async () => {
    logoutMode = 'success';
    await window.logout();
    return {authed:state.authed,csrf,renderCount};
  });
  expect(succeeded.authed).toBe(false);
  expect(succeeded.csrf).toBe('');
  expect(succeeded.renderCount).toBe(1);
});

test('existing Setting keys are readonly while a new key remains editable', async ({ page }) => {
  await mountReliability(page);
  const existing = await page.evaluate(() => {
    window.openSettingByKey('existing.key');
    const input = document.getElementById('f_setting_key');
    return {readOnly:input.readOnly,aria:input.getAttribute('aria-readonly')};
  });
  expect(existing).toEqual({readOnly:true,aria:'true'});

  const fresh = await page.evaluate(() => {
    window.openSettingByKey('new.key');
    const input = document.getElementById('f_setting_key');
    return {readOnly:input.readOnly,aria:input.getAttribute('aria-readonly')};
  });
  expect(fresh).toEqual({readOnly:false,aria:null});
});

test('legacy admin module names resolve to canonical destinations', async ({ page }) => {
  await page.setContent(`<!doctype html><html><body><script>
    window.calls = [];
    window.go = async section => { calls.push(['go', section]); return section; };
    window.tech = async section => { calls.push(['tech', section]); return section; };
  </script></body></html>`);
  await page.addScriptTag({content: routeAliases});

  const result = await page.evaluate(async () => {
    const backups = await window.go('backups');
    const activity = await window.go('activity');
    const sets = await window.go('sets');
    return {backups,activity,sets,calls:[...calls]};
  });

  expect(result.backups).toBe('system');
  expect(result.activity).toBe('dashboard');
  expect(result.sets).toBe('sets');
  expect(result.calls).toEqual([
    ['tech','system'],
    ['go','dashboard'],
    ['go','sets']
  ]);
});
