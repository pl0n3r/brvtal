import {test,expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
import {execFileSync} from 'node:child_process';

const settings=readFileSync(new URL('../../discadmin/settings-v2.js',import.meta.url),'utf8');
const status=readFileSync(new URL('../../discadmin/system-status-v2.js',import.meta.url),'utf8');

// #1856: el contrato original de #1840 ahora valida la separación de responsabilidades.
// El recorrido con datos sintéticos y el PEM completo están en la nueva suite Settings.
test('AC-05: Settings posee el editor; System status no puede modificar claves',async()=>{
  expect(settings).toContain('/api/admin-integration-secrets.php');
  expect(settings).toContain('data-secret-provider-save');
  expect(settings).toContain('data-secret-delete');
  expect(settings).toContain('integrationMutation("POST"');
  expect(settings).toContain('integrationMutation("DELETE"');
  expect(status).not.toContain('data-secret-input');
  expect(status).not.toContain('data-secret-action');
  expect(status).not.toContain('/api/admin-integration-secrets.php');
  expect(status).toContain('Configurar en Settings');
  expect(status).toContain('data-open-integration-settings');
});

test('AC-02: real PHP endpoint rejects guest and invalid admin CSRF without changing synthetic data',async({page})=>{
  const base=process.env.BRVTAL_REAL_STACK_URL;
  test.skip(!base || process.env.BRVTAL_REAL_STACK_FORCE!=='1',
    'Only exercise against a disposable authenticated PHP/MariaDB fixture');
  const dbName=process.env.BRVTAL_TEST_DB_NAME || '';
  expect(dbName).toMatch(/^brvtal_test[a-zA-Z0-9_]*$/);
  const api=base+'/api/admin-integration-secrets.php';
  const sentinel={key:'BRVTAL_SENTRY_DSN',value:'https://synthetic-only@sentry.invalid/101'};
  const count=()=>{
    const args=['-N','-B','-h',process.env.BRVTAL_TEST_DB_HOST||'127.0.0.1',
      '-P',String(process.env.BRVTAL_TEST_DB_PORT||'3306'),
      '-u',process.env.BRVTAL_TEST_DB_USER||'root',
      '-D',dbName,'-e',
      "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='integration_secrets'"];
    return execFileSync('mysql',args,{encoding:'utf8',
      env:{...process.env,MYSQL_PWD:process.env.BRVTAL_TEST_DB_PASS||''}}).trim();
  };
  const before=count();
  expect(before).toMatch(/^[01]$/);
  const guest=await page.request.post(api,{data:sentinel});
  expect(guest.status()).toBe(401);
  await page.goto(base+'/discadmin/');
  await page.locator('#admin-login-email').fill(process.env.BRVTAL_REAL_STACK_ADMIN_EMAIL||'ci-admin@brvtal.test');
  await page.locator('#admin-login-password').fill(process.env.BRVTAL_REAL_STACK_ADMIN_PASSWORD||'brvtal-ci-password');
  await page.getByRole('button',{name:'ENTER'}).click();
  await page.waitForFunction(()=>Boolean(window.state?.authed));
  const missing=await page.request.post(api,{data:sentinel});
  const invalid=await page.request.post(api,{data:sentinel,headers:{'X-CSRF-Token':'synthetic-invalid'}});
  const forbiddenDelete=await page.request.delete(api,{data:{key:sentinel.key}});
  for(const result of [missing,invalid,forbiddenDelete]) {
    expect(result.status()).toBe(403);
    expect((await result.json()).error).toBe('CSRF');
  }
  expect(count()).toBe(before);
});
