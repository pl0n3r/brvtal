import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const script = readFileSync(join(process.cwd(), 'discadmin/backups.js'), 'utf8');
const harness = 'http://127.0.0.1:4173/discadmin/e2e-backups.html';

const item = (id, status='ready') => ({
  id,
  status,
  created_at:'2026-09-12T07:00:00Z',
  created_by:{id:1,name:'Felipe'},
  deployment:{short_commit:'5367cb8',environment:'PRODUCTION',source:'git_checkout'},
  artifacts_bytes:2048,
  artifacts_size:'2.00 KB',
  restore_supported:false,
  components:{
    database:{status:'ready',available:true,bytes:1000,size:'1000 B',sha256:'a'.repeat(64),tables:12,rows:44},
    media_manifest:{status:'ready',available:true,bytes:600,size:'600 B',sha256:'b'.repeat(64),files:7},
    media_archive:{status:'not_requested',available:false,bytes:0,size:'0 B',sha256:null,files:null},
  },
});

function listPayload(items) {
  return {
    ok:true,
    data:{
      items,
      total:items.length,
      capabilities:{manual_create:true,media_archive:false,download:true,restore:false,delete:false,automation:true,drive_oauth:false},
      automation:{
        config:{enabled:false,timezone:'America/Bogota',cadence:{type:'daily',interval:1,time:'03:00'},scope:'full',include_media_archive:false,retention_local:7,drive:{enabled:false,folder:null}},
        next_run_at:null,last_run_at:null,last_success_at:null,last_result:null,last_duration_ms:null,
        drive:{connected:false,last_success_at:null,last_error:null},
      },
      private_storage:true,
    },
  };
}

test('Backups Foundation mounts inside System Status and creates an audited manual backup request with CSRF', async ({ page }) => {
  let items = [item('brvtal-20260912T070000Z-abcdef12')];
  let postBody = null;
  let postCsrf = null;
  let automationBody = null;

  await page.route(harness, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:`<!doctype html><html><body>
      <section id="system-status-v2">
        <div class="ssv2-panel">EXISTING STATUS</div>
        <details class="ssv2-advanced"><summary>ADVANCED DIAGNOSTICS</summary></details>
      </section>
      <script>const csrf='csrf-test-token';</script>
      <script>${script}</script>
    </body></html>`
  }));

  await page.route('**/discadmin/backups.php*', async route => {
    const request = route.request();
    if (request.method() === 'POST' && request.url().includes('action=automation')) {
      postCsrf = request.headers()['x-csrf-token'] || null;
      automationBody = request.postDataJSON();
      return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:listPayload(items).data.automation})});
    }
    if (request.method() === 'POST') {
      postCsrf = request.headers()['x-csrf-token'] || null;
      postBody = request.postDataJSON();
      const created = item('brvtal-20260912T071500Z-fedcba98');
      items = [created, ...items];
      return route.fulfill({status:201,contentType:'application/json',body:JSON.stringify({ok:true,data:created})});
    }
    return route.fulfill({contentType:'application/json',body:JSON.stringify(listPayload(items))});
  });

  await page.goto(harness);

  const backups = page.locator('#ssv2-backups');
  await expect(backups).toBeVisible();
  await expect(backups).toContainText('BACKUPS');
  await expect(backups).toContainText('PRIVATE / MANUAL');
  await expect(backups).toContainText('1');
  await expect(backups).toContainText('READY');
  await expect(backups).toContainText('RESTORE');
  await expect(backups).toContainText('OFF');
  await expect(backups).toContainText('NO DELETE / NO RESTORE IN V1');
  await expect(backups).toContainText('PRIVATE STORAGE VERIFIED');

  const dbLink = backups.locator('a', {hasText:'DATABASE SQL'}).first();
  await expect(dbLink).toHaveAttribute('href', /action=download.*component=database/);
  await expect(backups.locator('a', {hasText:'MEDIA MANIFEST'}).first()).toHaveAttribute('href', /component=media_manifest/);
  await expect(backups.locator('a', {hasText:'MEDIA ZIP'})).toHaveCount(0);
  await expect(backups.locator('button', {hasText:'CREATE + MEDIA ZIP'})).toBeDisabled();

  page.once('dialog', dialog => dialog.accept());
  await backups.locator('button', {hasText:'CREATE BACKUP'}).click();

  await expect.poll(() => postCsrf).toBe('csrf-test-token');
  expect(postBody).toEqual({include_media_archive:false,scope:'full'});
  await expect(backups).toContainText('2');
  await expect(backups).toContainText('brvtal-20260912T071500Z-fedcba98');
  await expect(backups).toContainText('AUTOMATION');
  await expect(backups).toContainText('America/Bogota');
  await expect(backups).toContainText('OFF-SITE / GOOGLE DRIVE');
  await expect(backups).toContainText('AUTH REQUIRED');

  await backups.locator('[data-backup-enabled]').check();
  await backups.locator('[data-backup-cadence]').selectOption('hours');
  await backups.locator('[data-backup-interval]').fill('6');
  await backups.locator('[data-backup-scope]').selectOption('database');
  await backups.locator('[data-backup-retention]').fill('5');
  await backups.locator('.backup-save').click();
  await expect.poll(() => automationBody).not.toBeNull();
  expect(automationBody.enabled).toBe(true);
  expect(automationBody.cadence.type).toBe('hours');
  expect(automationBody.cadence.interval).toBe(6);
  expect(automationBody.scope).toBe('database');
  expect(automationBody.retention_local).toBe(5);
});
