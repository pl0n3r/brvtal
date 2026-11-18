import { test, expect } from '@playwright/test';
import { existsSync, readFileSync } from 'node:fs';
const baseUrl=process.env.BRVTAL_REAL_STACK_URL||'http://127.0.0.1:4174';
const adminEmail=process.env.BRVTAL_REAL_STACK_RECOVERY_ADMIN_EMAIL||'ci-recovery@brvtal.test';
const capture=process.env.BRVTAL_MAIL_CAPTURE_FILE||'';
const recoveredPassword='BRVTAL-CI-Recovered-2026!', changedPassword='BRVTAL-CI-Changed-2026!';
function messages(){if(!capture||!existsSync(capture))return[];return readFileSync(capture,'utf8').trim().split('\n').filter(Boolean).map(line=>JSON.parse(line));}
test('admin recovers account then changes password in Security',async({page})=>{
 await page.goto(baseUrl+'/discadmin/forgot-password.php');await page.locator('#recovery-email').fill(adminEmail);await page.getByRole('button',{name:'ENVIAR INSTRUCCIONES'}).click();await expect(page.locator('#recovery-message')).toContainText('Si la cuenta existe');
 await expect.poll(()=>messages().filter(row=>row.purpose==='password_recovery').length,{timeout:10000}).toBe(1);
 const recovery=messages().find(row=>row.purpose==='password_recovery');const match=String(recovery?.body||'').match(/https?:\/\/[^\s]+\/discadmin\/reset-password\.php#token=([a-f0-9]{64})/i);expect(match).not.toBeNull();
 await page.goto(match[0]);await expect.poll(()=>new URL(page.url()).hash).toBe('');await page.locator('#new-password').fill(recoveredPassword);await page.locator('#confirm-password').fill(recoveredPassword);await page.getByRole('button',{name:'ACTUALIZAR CONTRASEÑA'}).click();await expect(page.locator('#recovery-message')).toContainText('Contraseña actualizada');
 await page.goto(baseUrl+'/discadmin/');await page.locator('#admin-login-email').fill(adminEmail);await page.locator('#admin-login-password').fill(recoveredPassword);await page.getByRole('button',{name:'ENTER'}).click();await page.waitForFunction(()=>Boolean(window.state?.authed));
 await page.evaluate(()=>window.go?.('security'));await expect(page.locator('#passwordChangeCard')).toBeVisible();await page.locator('#currentPassword').fill(recoveredPassword);await page.locator('#newPassword').fill(changedPassword);await page.locator('#confirmPassword').fill(changedPassword);await page.locator('#changePassword').click();await expect(page.locator('#passwordMessage')).toContainText('Password changed');
 await page.evaluate(()=>window.logout?.());await expect(page.locator('#admin-login-email')).toBeVisible();await page.locator('#admin-login-email').fill(adminEmail);await page.locator('#admin-login-password').fill(changedPassword);await page.getByRole('button',{name:'ENTER'}).click();await page.waitForFunction(()=>Boolean(window.state?.authed));
 expect(messages().some(row=>String(row.purpose).startsWith('password_changed_'))).toBeTruthy();
});
