import {test,expect} from '@playwright/test';

const origin='http://127.0.0.1:4174';
const endpoint=origin+'/api/ticketing/admin-finances.php';
const creds=role=>({email:role+'-2191@example.test',password:'brvtal-fixture-2191'});
async function login(request,role) {
  const response=await request.post(origin+'/api/index.php/auth',{data:creds(role)});
  expect(response.ok()).toBeTruthy();
  const session=await response.json();
  expect(session.ok).toBe(true);
  expect(session.csrf).toBeTruthy();
  return session.csrf;
}
async function fill(pane,concept,amount,reason) {
  await pane.getByLabel('Concepto').fill(concept);
  await pane.getByLabel('Importe (COP)').fill(amount);
  await pane.getByLabel('Fecha (Bogotá)').fill('2026-11-26');
  await pane.getByLabel('Motivo del registro o ajuste').fill(reason);
}
async function write(page,pane,label) {
  const pending=page.waitForResponse(r=>r.url()===endpoint&&r.request().method()==='POST');
  await pane.getByRole('button',{name:label,exact:true}).click();
  const response=await pending;
  expect(response.ok()).toBeTruthy();
  const data=await response.json();
  expect(data.ok).toBe(true);
  await expect(pane.locator('[data-ledger-status]')).toContainText('Registro guardado');
  return data.id;
}
test('2191 actual authenticated expense and budget persistence',async({page,browser})=>{
  test.setTimeout(90000);
  expect(process.env.BRVTAL_FINANCE_2191_FIXTURE).toBe('1');
  await page.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():route.abort());
  const csrf=await login(page.request,'admin');
  await page.goto(origin+'/discadmin/?module=ticketing&tab=finance');
  const ledger=page.locator('#tk-finance-ledger');
  await page.getByRole('button',{name:'Gastos',exact:true}).click();
  await expect(ledger).toHaveAttribute('data-ledger-csrf',csrf);
  await ledger.getByLabel('Evento del libro').selectOption('6101');
  await expect(ledger.getByText('No hay gastos registrados.')).toBeVisible();
  await fill(ledger,'Sonido fixture','45000','Registro fixture');
  const first=await write(page,ledger,'Guardar registro');
  await ledger.locator('[data-ledger-row="'+first+'"]').getByRole('button',{name:'Editar'}).click();
  await fill(ledger,'Sonido ajustado','50000','Ajuste fixture');
  await ledger.getByLabel('Estado').selectOption('paid');
  const second=await write(page,ledger,'Guardar registro');
  await expect(ledger.locator('[data-ledger-row="'+second+'"]')).toContainText('Pagado');
  await ledger.getByLabel('Tipo de registro').selectOption('budget');
  await ledger.getByLabel('Categoría').selectOption('produccion');
  await ledger.getByLabel('Importe (COP)').fill('90000');
  await ledger.getByLabel('Fecha (Bogotá)').fill('2026-11-26');
  await ledger.getByLabel('Motivo del registro o ajuste').fill('Presupuesto fixture');
  await write(page,ledger,'Guardar registro');
  const stale={kind:'expense',action:'adjust',event_id:6101,previous_id:Number(first),
    reason:'Revisión obsoleta',values:{category:'produccion',concept:'No persistir',amount_cop:1,entry_date:'2026-11-26',status:'paid'}};
  const invalid=await page.request.post(endpoint,{headers:{'X-CSRF-Token':'invalid'},data:stale});
  expect(invalid.status()).toBe(419);
  const conflict=await page.request.post(endpoint,{headers:{'X-CSRF-Token':csrf},data:stale});
  expect(conflict.status()).toBe(409);
  expect((await conflict.json()).error).toBe('LEDGER_STALE_REVISION');
  const door=await browser.newContext();
  try {
    const doorCsrf=await login(door.request,'door');
    expect((await door.request.get(endpoint+'?ledger=1')).status()).toBe(403);
    expect((await door.request.post(endpoint,{headers:{'X-CSRF-Token':doorCsrf},data:stale})).status()).toBe(403);
  } finally {await door.close();}
  // Separate expense and budget tables can have the same ID; scope to expense rows.
  await ledger.locator('[data-ledger-row="'+second+'"]').getByRole('button',{name:'Editar'}).click();
  await ledger.getByLabel('Motivo del registro o ajuste').fill('Anulación fixture');
  const last=await write(page,ledger,'Anular registro');
  await expect(ledger.locator('[data-ledger-row="'+last+'"]')).toContainText('Anulado');
  const other=await page.request.get(endpoint+'?ledger=1&event_id=6102');
  expect(other.ok()).toBeTruthy();
  expect(await other.json()).toMatchObject({ok:true,expenses:[],budgets:[]});
  const result=await page.request.get(endpoint+'?ledger=1&event_id=6101');
  expect(result.ok()).toBeTruthy();
  const data=await result.json();
  expect(data.expenses).toHaveLength(1);
  expect(data.expenses[0]).toMatchObject({id:last,amount_cop:'50000',status:'void'});
  expect(data.budgets).toHaveLength(1);
  expect(data.budgets[0]).toMatchObject({category:'produccion',amount_cop:'90000',status:'active'});
});
