import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const authBoundaryJs = readFileSync(join(process.cwd(),'discadmin/admin-auth-boundary.js'),'utf8');

async function setSameOriginContent(page) {
  await page.route('http://127.0.0.1:4173/**', route => route.fulfill({
    status:200,
    contentType:'text/html',
    body:'<!doctype html><html><body></body></html>'
  }));
  await page.goto('/discadmin');
}


test('admin GET bursts are bounded to two in-flight requests', async ({page}) => {
  await setSameOriginContent(page);
  await page.evaluate(() => {
    window.state={authed:true};
    window.csrf='csrf';
    window.__activeReads=0;
    window.__maxReads=0;
    window.fetch=async input => {
      const url=String(typeof input==='string'?input:input?.url||'');
      if(url.startsWith('/api/index.php/settings?key=burst-')){
        window.__activeReads+=1;
        window.__maxReads=Math.max(window.__maxReads,window.__activeReads);
        await new Promise(resolve=>setTimeout(resolve,30));
        window.__activeReads-=1;
        return new Response(JSON.stringify({ok:true,data:[]}),{
          status:200,headers:{'Content-Type':'application/json'}
        });
      }
      return new Response('{}',{status:200,headers:{'Content-Type':'application/json'}});
    };
  });
  await page.evaluate(source => window.eval(source),authBoundaryJs);

  const result=await page.evaluate(async () => {
    const responses=await Promise.all(
      Array.from({length:6},(_,index)=>window.fetch(
        '/api/index.php/settings?key=burst-'+index,
        {method:'GET',credentials:'same-origin',cache:'no-store'}
      ))
    );
    return {
      statuses:responses.map(response=>response.status),
      maxReads:window.__maxReads,
      diagnostics:window.BRVTALAdminAuthBoundary.diagnostics()
    };
  });

  expect(result.statuses).toEqual([200,200,200,200,200,200]);
  expect(result.maxReads).toBe(2);
  expect(result.diagnostics).toMatchObject({
    adminGetActive:0,
    adminGetQueued:0,
    adminGetLimit:2
  });
});

test('auth and mutations bypass a saturated admin GET queue', async ({page}) => {
  await setSameOriginContent(page);
  await page.evaluate(() => {
    window.state={authed:true};
    window.csrf='csrf';
    window.__activeReads=0;
    window.__maxReads=0;
    window.__authRequests=0;
    window.__mutationRequests=0;
    window.__releaseReads=null;
    window.__readBarrier=new Promise(resolve=>{ window.__releaseReads=resolve; });
    window.fetch=async (input,init={}) => {
      const url=String(typeof input==='string'?input:input?.url||'');
      const method=String(init?.method||'GET').toUpperCase();
      if(url.startsWith('/api/index.php/settings?key=hold-') && method==='GET'){
        window.__activeReads+=1;
        window.__maxReads=Math.max(window.__maxReads,window.__activeReads);
        await window.__readBarrier;
        window.__activeReads-=1;
        return new Response(JSON.stringify({ok:true,data:[]}),{
          status:200,headers:{'Content-Type':'application/json'}
        });
      }
      if(url==='/api/index.php/auth'){
        window.__authRequests+=1;
        return new Response(JSON.stringify({ok:true,authenticated:true,csrf:'fresh'}),{
          status:200,headers:{'Content-Type':'application/json'}
        });
      }
      if(url==='/api/index.php/settings' && method==='POST'){
        window.__mutationRequests+=1;
        return new Response(JSON.stringify({ok:true}),{
          status:200,headers:{'Content-Type':'application/json'}
        });
      }
      return new Response('{}',{status:404});
    };
  });
  await page.evaluate(source => window.eval(source),authBoundaryJs);

  const result=await page.evaluate(async () => {
    const reads=[
      window.fetch('/api/index.php/settings?key=hold-1',{method:'GET',credentials:'same-origin'}),
      window.fetch('/api/index.php/settings?key=hold-2',{method:'GET',credentials:'same-origin'}),
      window.fetch('/api/index.php/settings?key=hold-3',{method:'GET',credentials:'same-origin'})
    ];
    while(window.__activeReads<2) await new Promise(resolve=>setTimeout(resolve,0));

    const timeout=new Promise(resolve=>setTimeout(()=>resolve('timeout'),500));
    const authResult=await Promise.race([
      window.BRVTALAdminAuthBoundary.auth({force:true}).then(()=> 'ok'),
      timeout
    ]);
    const mutationResult=await Promise.race([
      window.fetch('/api/index.php/settings',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:'{}',
        credentials:'same-origin'
      }).then(response=>response.status),
      new Promise(resolve=>setTimeout(()=>resolve('timeout'),500))
    ]);
    const during=window.BRVTALAdminAuthBoundary.diagnostics();
    window.__releaseReads();
    await Promise.all(reads);
    return {
      authResult,
      mutationResult,
      authRequests:window.__authRequests,
      mutationRequests:window.__mutationRequests,
      maxReads:window.__maxReads,
      during,
      after:window.BRVTALAdminAuthBoundary.diagnostics()
    };
  });

  expect(result).toMatchObject({
    authResult:'ok',
    mutationResult:200,
    authRequests:1,
    mutationRequests:1,
    maxReads:2,
    during:{adminGetActive:2,adminGetQueued:1,adminGetLimit:2},
    after:{adminGetActive:0,adminGetQueued:0,adminGetLimit:2}
  });
});

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
  await setSameOriginContent(page);
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
  expect(await page.evaluate(() => window.BRVTALAdminAuthBoundary.isAdminRequest('/api/index.php/settings?key=home.hero.slider'))).toBe(true);

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

test('concurrent admin GET 401 responses share one forced auth revalidation', async ({page}) => {
  await setSameOriginContent(page);
  await page.evaluate(() => {
    window.state={authed:true};
    window.csrf='stale-csrf';
    window.__authRequests=0;
    window.__targetRequests=0;
    window.__releaseAuth=null;
    window.__authBarrier=new Promise(resolve => { window.__releaseAuth=resolve; });
    window.fetch=async input => {
      const url=String(typeof input==='string'?input:input?.url||'');
      if(url==='/api/index.php/auth'){
        window.__authRequests+=1;
        await window.__authBarrier;
        return new Response(JSON.stringify({ok:true,authenticated:true,csrf:'fresh-csrf'}),{
          status:200,headers:{'Content-Type':'application/json'}
        });
      }
      if(url.startsWith('/api/index.php/settings?key=')){
        window.__targetRequests+=1;
        if(window.__targetRequests<=2){
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

  const pending=page.evaluate(async () => {
    const requests=[
      window.fetch('/api/index.php/settings?key=one',{method:'GET',credentials:'same-origin'}),
      window.fetch('/api/index.php/settings?key=two',{method:'GET',credentials:'same-origin'})
    ];
    while(window.__authRequests<1) await new Promise(resolve=>setTimeout(resolve,0));
    window.__releaseAuth();
    const responses=await Promise.all(requests);
    return {
      statuses:responses.map(response=>response.status),
      authRequests:window.__authRequests,
      targetRequests:window.__targetRequests,
      diagnostics:window.BRVTALAdminAuthBoundary.diagnostics()
    };
  });
  const result=await pending;

  expect(result.statuses).toEqual([200,200]);
  expect(result.authRequests).toBe(1);
  expect(result.targetRequests).toBe(4);
  expect(result.diagnostics).toMatchObject({
    revalidationCount:2,
    revalidationAttempted:2,
    revalidationSucceeded:2,
    revalidationFailed:0
  });
});

test('admin revalidation 503 stays fail-closed without expiring a valid session', async ({page}) => {
  await setSameOriginContent(page);
  await page.evaluate(() => {
    window.state={authed:true};
    window.csrf='current-csrf';
    document.body.innerHTML='<div id="modal" class="open"></div>';
    window.__targetRequests=0;
    window.__authRequests=0;
    window.closeModal=()=>document.getElementById('modal')?.classList.remove('open');
    window.render=()=>{};
    window.fetch=async (input) => {
      const url=String(typeof input==='string'?input:input?.url||'');
      if(url==='/api/index.php/auth'){
        window.__authRequests+=1;
        return new Response(JSON.stringify({ok:false,error:'AUTH_REVALIDATION_UNAVAILABLE'}),{
          status:503,headers:{'Content-Type':'application/json'}
        });
      }
      if(url==='/api/index.php/settings?key=home.hero.slider'){
        window.__targetRequests+=1;
        return new Response(JSON.stringify({ok:false,error:'AUTH_REVALIDATION_UNAVAILABLE'}),{
          status:503,headers:{'Content-Type':'application/json'}
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
      modalOpen:document.getElementById('modal')?.classList.contains('open') === true,
      diagnostics:window.BRVTALAdminAuthBoundary.diagnostics(),
    };
  });

  expect(result).toMatchObject({
    status:503,
    targetRequests:1,
    authRequests:0,
    authed:true,
    csrf:'current-csrf',
    modalOpen:true,
    diagnostics:{
      revalidationCount:0,
      revalidationAttempted:0,
      revalidationSucceeded:0,
      revalidationFailed:0
    }
  });
});

test('admin mutation 401 is never revalidated or retried', async ({page}) => {
  await setSameOriginContent(page);
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
  await setSameOriginContent(page);
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
