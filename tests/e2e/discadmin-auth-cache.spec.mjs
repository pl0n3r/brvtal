import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const authBoundaryJs = readFileSync(join(process.cwd(),'discadmin/admin-auth-boundary.js'),'utf8');

test('shared admin auth memoizes concurrent auth/CSRF reads into one request', async ({page}) => {
  await page.setContent('<!doctype html><html><body></body></html>');
  await page.evaluate(() => {
    window.state={authed:true};
    window.csrf='';
    window.__authRequests=0;
    window.fetch=async url => {
      if(String(url)==='/api/index.php/auth'){
        window.__authRequests+=1;
        await new Promise(resolve=>setTimeout(resolve,40));
        return new Response(JSON.stringify({ok:true,authenticated:true,csrf:'shared-csrf'}),{
          status:200,
          headers:{'Content-Type':'application/json'}
        });
      }
      return new Response('{}',{status:404});
    };
  });
  await page.evaluate(source => window.eval(source),authBoundaryJs);

  const result=await page.evaluate(async () => {
    const boundary=window.BRVTALAdminAuthBoundary;
    const values=await Promise.all([
      boundary.auth(),
      boundary.csrfToken(),
      boundary.auth(),
      boundary.csrfToken(),
    ]);
    return {
      requests:window.__authRequests,
      csrf:window.csrf,
      values:values.map(value=>typeof value==='string'?value:value.csrf)
    };
  });

  expect(result.requests).toBe(1);
  expect(result.csrf).toBe('shared-csrf');
  expect(result.values).toEqual(['shared-csrf','shared-csrf','shared-csrf','shared-csrf']);

  await page.evaluate(async () => {
    await window.BRVTALAdminAuthBoundary.auth({force:true});
  });
  expect(await page.evaluate(() => window.__authRequests)).toBe(2);
});

test('expired auth clears memoized CSRF before the next protected action', async ({page}) => {
  await page.setContent('<!doctype html><html><body></body></html>');
  await page.evaluate(() => {
    window.state={authed:true};
    window.csrf='stale';
    window.__authRequests=0;
    window.fetch=async()=>{
      window.__authRequests+=1;
      return new Response(JSON.stringify({ok:true,authenticated:true,csrf:'fresh-'+window.__authRequests}),{
        status:200,headers:{'Content-Type':'application/json'}
      });
    };
  });
  await page.evaluate(source => window.eval(source),authBoundaryJs);
  await page.evaluate(() => window.BRVTALAdminAuthBoundary.auth());
  await page.evaluate(() => window.BRVTALAdminAuthBoundary.expireSession());
  expect(await page.evaluate(() => window.csrf)).toBe('');
  expect(await page.evaluate(() => window.state.authed)).toBe(false);
  await page.evaluate(() => { window.state.authed = true; });
  expect(await page.evaluate(() => window.BRVTALAdminAuthBoundary.csrfToken())).toBe('fresh-2');
  expect(await page.evaluate(() => window.__authRequests)).toBe(2);
});
