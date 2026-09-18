import { test, expect } from '@playwright/test';

const baseUrl = process.env.BRVTAL_REAL_STACK_URL || '';
const adminEmail = process.env.BRVTAL_REAL_STACK_ADMIN_EMAIL || 'ci-admin@brvtal.test';
const adminPassword = process.env.BRVTAL_REAL_STACK_ADMIN_PASSWORD || '';

test.skip(!baseUrl || !adminPassword, 'BRVTAL_REAL_STACK_URL and admin credentials are required for the real-stack smoke');

test('Theme settings preserve active reference integrity through the authenticated API', async ({ page }, testInfo) => {
  const login = await page.request.post(`${baseUrl}/api/index.php/auth`, {
    data:{email:adminEmail,password:adminPassword},
  });
  expect(login.ok(), `Admin login failed with HTTP ${login.status()}`).toBeTruthy();
  const auth = await login.json();
  const headers = {'X-CSRF-Token':auth.csrf};
  const runKey = `${Date.now().toString(36)}-${testInfo.workerIndex}`;
  const slug = `ci-theme-${runKey}`;
  const missingSlug = `ci-missing-${runKey}`;
  const themeKey = `theme.${slug}`;

  const removeSetting = async key => {
    try {
      await page.request.delete(`${baseUrl}/api/index.php/settings?key=${encodeURIComponent(key)}`, {headers});
    } catch (_) {
      // Cleanup is best-effort inside the isolated CI database.
    }
  };

  try {
    const createTheme = await page.request.post(`${baseUrl}/api/index.php/settings`, {
      headers,
      data:{
        setting_key:themeKey,
        setting_value:JSON.stringify({name:'CI THEME',accent:'#ff1717'}),
        is_json:1,
      },
    });
    expect(createTheme.ok()).toBeTruthy();

    const missingActivation = await page.request.post(`${baseUrl}/api/index.php/settings`, {
      headers,
      data:{setting_key:'theme.active',setting_value:missingSlug,is_json:0},
    });
    expect(missingActivation.status()).toBe(422);
    expect(await missingActivation.json()).toMatchObject({error:'THEME_NOT_FOUND',field:'setting_value'});

    const activate = await page.request.post(`${baseUrl}/api/index.php/settings`, {
      headers,
      data:{setting_key:'theme.active',setting_value:slug,is_json:0},
    });
    expect(activate.ok()).toBeTruthy();

    const corruptActiveDefinition = await page.request.post(`${baseUrl}/api/index.php/settings`, {
      headers,
      data:{setting_key:themeKey,setting_value:'legacy-value',is_json:0},
    });
    expect(corruptActiveDefinition.status()).toBe(422);
    expect(await corruptActiveDefinition.json()).toMatchObject({
      error:'INVALID_SETTING_JSON',
      field:'setting_value',
    });

    const settingsResponse = await page.request.get(`${baseUrl}/api/index.php/settings`);
    expect(settingsResponse.ok()).toBeTruthy();
    const settings = (await settingsResponse.json()).data;
    const persistedTheme = settings.find(row => row.setting_key === themeKey);
    expect(Number(persistedTheme?.is_json)).toBe(1);
    expect(JSON.parse(persistedTheme?.setting_value || '{}')).toMatchObject({name:'CI THEME'});

    const blockedDelete = await page.request.delete(
      `${baseUrl}/api/index.php/settings?key=${encodeURIComponent(themeKey)}`,
      {headers},
    );
    expect(blockedDelete.status()).toBe(409);
    expect(await blockedDelete.json()).toMatchObject({
      error:'ACTIVE_THEME_DELETE_BLOCKED',
      field:'setting_key',
    });
  } finally {
    await removeSetting('theme.active');
    await removeSetting(themeKey);
  }
});
