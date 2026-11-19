import { test, expect } from '@playwright/test';

const baseUrl = process.env.BRVTAL_REAL_STACK_URL || '';
const adminEmail = process.env.BRVTAL_REAL_STACK_ADMIN_EMAIL || 'ci-admin@brvtal.test';
const adminPassword = process.env.BRVTAL_REAL_STACK_ADMIN_PASSWORD || '';

test.skip(!baseUrl || !adminPassword, 'Real-stack service and admin credentials required');

test('DISCADMIN persists optional venue address in atomic Event workflow and allows clearing it', async ({page}, testInfo) => {
  const authResponse = await page.request.post(baseUrl + '/api/index.php/auth', {
    data:{email:adminEmail,password:adminPassword}
  });
  expect(authResponse.ok()).toBeTruthy();
  const auth = await authResponse.json();
  expect(auth.csrf).toBeTruthy();
  const runKey = Date.now().toString(36) + '-' + testInfo.workerIndex;
  const slug = 'ci-venue-address-' + runKey;
  let id = 0;
  try {
    await page.goto(baseUrl + '/discadmin/?module=events', {waitUntil:'domcontentloaded'});
    await page.getByRole('button',{name:'+ NEW EVENT',exact:true}).click();
    await expect(page.locator('#eventModal')).toHaveClass(/open/);
    await page.locator('#e_title').fill('CI ADDRESS ' + runKey);
    await page.locator('#e_slug').fill(slug);
    await page.locator('#e_description').fill('Real-stack address persistence test');
    await page.locator('#eventModal [data-step="2"]').click();
    await page.locator('#e_city').fill('Pereira');
    await page.locator('#e_venue').fill('Sala CI');
    await page.locator('#e_venue_address').fill('Carrera 9 # 10-11, Pereira');
    await expect(page.locator('#e_venue_map_preview')).toHaveAttribute(
      'href', /query=Carrera%209%20%23%2010-11%2C%20Pereira/
    );
    const saveResponse = page.waitForResponse(resp =>
      resp.request().method() === 'POST' && resp.url().endsWith('/api/event-workflow.php')
    );
    await page.locator('#cc-top-saveBtn').click();
    const save = await saveResponse;
    expect(save.ok(), 'Atomic event workflow first save failed').toBeTruthy();
    const json = await save.json();
    id = Number(json?.data?.event?.id || 0);
    expect(id).toBeGreaterThan(0);
    const persisted = await (await page.request.get(baseUrl + '/api/index.php/events/' + id)).json();
    expect(persisted.data.venue_address).toBe('Carrera 9 # 10-11, Pereira');

    await page.locator('#e_venue_address').fill('');
    const clearResponse = page.waitForResponse(resp =>
      resp.request().method() === 'POST' && resp.url().endsWith('/api/event-workflow.php')
    );
    await page.locator('#cc-top-saveBtn').click();
    const cleared = await clearResponse;
    expect(cleared.ok()).toBeTruthy();
    const fresh = await (await page.request.get(baseUrl + '/api/index.php/events/' + id)).json();
    expect(fresh.data.venue_address).toBe('');
  } finally {
    if (id > 0) {
      const cleanup = await page.request.delete(baseUrl + '/api/index.php/events/' + id, {
        headers:{'X-CSRF-Token':auth.csrf}
      });
      expect(cleanup.ok(), 'Address fixture cleanup failed').toBeTruthy();
    }
  }
});
