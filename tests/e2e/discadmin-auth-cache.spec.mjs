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


test('same-origin admin GET revalidates once after transient 401 and retries exactly once', async ({page}) => {
  await page.setContent('<!doctype html><html><body></body></html>');
  await page.evaluate(() => {
    window.state={authed:true};
    window.csrf='stale-csrf';
    window.__targetRequests=0;
    window.__authRequests=0;
    window.fetch=async (input, init={}) => {
      const url=String(typeof input==='string'?input:input?.url||'');
      if(url==='/api/index.php/auth'){
        window.__authRequests+=1;
        return new Response(JSON.stringify({ok:true,authenticated:true,csrf:'fresh-csrf'}),{
          status:200,headers:{'Content-Type':'application/json'}
        });
      }
      if(url==='/api/index.php/settings?key=home.hero.slider'){
        window.__targetRequests+=1;
        if(window.__targetRequests===1){
          return new Response(JSON.stringify({ok:false,error:'AUTH_REQUIRED'}),{
            status:401,headers:{'Content-Type':'application/json'}
          });
        }
        return new Response(JSON.stringify({ok:true,data:[]}),{
          status:200,headers:{'Content-Type':'application/json'}
        });
      }
      return new Response('{}',{status:404});
    };
  });
  await page.evaluate(source => window.eval(source),authBoundaryJs);

  const result=await page.evaluate(async () => {
    const response=await window.fetch('/api/index.php/settings?key=home.hero.slider',{
      method:'GET',credentials:'same-origin',cache:'no-store'
    });
    return {
      status:response.status,
      targetRequests:window.__targetRequests,
      authRequests:window.__authRequests,
      authed:window.state.authed,
      csrf:window.csrf,
    };
  });

  expect(result).toEqual({
    status:200,
    targetRequests:2,
    authRequests:1,
    authed:true,
    csrf:'fresh-csrf',
  });
});

test('admin mutation 401 is never revalidated or retried', async ({page}) => {
  await page.setContent('<!doctype html><html><body></body></html>');
  await page.evaluate(() => {
    window.state={authed:true};
    window.csrf='csrf';
    window.__targetRequests=0;
    window.__authRequests=0;
    window.fetch=async (input, init={}) => {
      const url=String(typeof input==='string'?input:input?.url||'');
      if(url==='/api/index.php/auth'){
        window.__authRequests+=1;
        return new Response(JSON.stringify({ok:true,authenticated:true,csrf:'fresh'}),{
          status:200,headers:{'Content-Type':'application/json'}
        });
      }
      if(url==='/api/index.php/settings'){
        window.__targetRequests+=1;
        return new Response(JSON.stringify({ok:false,error:'AUTH_REQUIRED'}),{
          status:401,headers:{'Content-Type':'application/json'}
        });
      }
      return new Response('{}',{status:404});
    };
  });
  await page.evaluate(source => window.eval(source),authBoundaryJs);

  const result=await page.evaluate(async () => {
    const response=await window.fetch('/api/index.php/settings',{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:'{}',
      credentials:'same-origin'
    });
    return {
      status:response.status,
      targetRequests:window.__targetRequests,
      authRequests:window.__authRequests,
      authed:window.state.authed,
      csrf:window.csrf,
    };
  });

  expect(result.status).toBe(401);
  expect(result.targetRequests).toBe(1);
  expect(result.authRequests).toBe(0);
  expect(result.authed).toBe(false);
  expect(result.csrf).toBe('');
});


test('pending forced auth cannot restore CSRF after a concurrent mutation expires the session', async ({page}) => {
  await page.setContent('<!doctype html><html><body></body></html>');
  await page.evaluate(() => {
    window.state={authed:true};
    window.csrf='stale-csrf';
    window.__targetRequests=0;
    window.__mutationRequests=0;
    window.__authRequests=0;
    window.__releaseAuth=null;
    window.__authBarrier=new Promise(resolve => { window.__releaseAuth=resolve; });
    window.fetch=async (input, init={}) => {
      const url=String(typeof input==='string'?input:input?.url||'');
      if(url==='/api/index.php/auth'){
        window.__authRequests+=1;
        await window.__authBarrier;
        return new Response(JSON.stringify({ok:true,authenticated:true,csrf:'late-csrf'}),{
          status:200,headers:{'Content-Type':'application/json'}
        });
      }
      if(url==='/api/index.php/settings?key=home.hero.slider'){
        window.__targetRequests+=1;
        return new Response(JSON.stringify({ok:false,error:'AUTH_REQUIRED'}),{
          status:401,headers:{'Content-Type':'application/json'}
        });
      }
      if(url==='/api/index.php/settings'){
        window.__mutationRequests+=1;
        return new Response(JSON.stringify({ok:false,error:'AUTH_REQUIRED'}),{
          status:401,headers:{'Content-Type':'application/json'}
        });
      }
      return new Response('{}',{status:404});
    };
  });
  await page.evaluate(source => window.eval(source),authBoundaryJs);

  const result=await page.evaluate(async () => {
    const getPromise=window.fetch('/api/index.php/settings?key=home.hero.slider',{
      method:'GET',credentials:'same-origin',cache:'no-store'
    });
    while(window.__authRequests<1) await new Promise(resolve=>setTimeout(resolve,0));
    const mutation=await window.fetch('/api/index.php/settings',{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:'{}',
      credentials:'same-origin'
    });
    window.__releaseAuth();
    const getResponse=await getPromise;
    return {
      getStatus:getResponse.status,
      mutationStatus:mutation.status,
      targetRequests:window.__targetRequests,
      mutationRequests:window.__mutationRequests,
      authRequests:window.__authRequests,
      authed:window.state.authed,
      csrf:window.csrf,
    };
  });

  expect(result).toEqual({
    getStatus:401,
    mutationStatus:401,
    targetRequests:1,
    mutationRequests:1,
    authRequests:1,
    authed:false,
    csrf:'',
  });
});
