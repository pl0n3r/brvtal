import { test, expect } from '@playwright/test';

const baseUrl = process.env.BRVTAL_REAL_STACK_URL || '';
const adminEmail = process.env.BRVTAL_REAL_STACK_ADMIN_EMAIL || 'ci-admin@brvtal.test';
const adminPassword = process.env.BRVTAL_REAL_STACK_ADMIN_PASSWORD || '';

test.skip(!baseUrl || !adminPassword, 'BRVTAL real-stack URL and admin credentials are required');

const settingKey = 'home.hero.slider';

async function login(page) {
  const response = await page.request.post(`${baseUrl}/api/index.php/auth`, {
    data:{email:adminEmail,password:adminPassword},
  });
  expect(response.ok(), `Admin login failed with HTTP ${response.status()}`).toBeTruthy();
  return response.json();
}

async function readSetting(page) {
  const response = await page.request.get(
    `${baseUrl}/api/index.php/settings?key=${encodeURIComponent(settingKey)}`
  );
  expect(response.ok()).toBeTruthy();
  const rows = (await response.json()).data || [];
  return rows[0] || null;
}

async function saveSetting(page, csrf, config) {
  return page.request.post(`${baseUrl}/api/index.php/settings`, {
    headers:{'X-CSRF-Token':csrf},
    data:{
      setting_key:settingKey,
      setting_value:JSON.stringify(config),
      is_json:1,
    },
  });
}


test('Banners uses bounded bootstrap reads on the authenticated real stack', async ({ page }) => {
  await login(page);

  const observed = [];
  page.on('request', request => {
    const url = new URL(request.url());
    if (url.pathname === '/api/index.php/settings' || url.pathname === '/api/index.php/media') {
      observed.push(url.pathname + url.search);
    }
  });

  await page.goto(`${baseUrl}/discadmin/`);
  await page.getByRole('button', {name:'BANNERS'}).click();
  await expect(page.locator('.hero-manager')).toBeVisible();

  expect(observed).toContain('/api/index.php/settings?key=home.hero.slider');
  expect(observed).toContain('/api/index.php/media?view=hero-picker');
  expect(observed).not.toContain('/api/index.php/settings');
  expect(observed).not.toContain('/api/index.php/media');

  const settingsResponse = await page.request.get(
    `${baseUrl}/api/index.php/settings?key=${encodeURIComponent(settingKey)}`
  );
  expect(settingsResponse.ok()).toBeTruthy();
  const settingsRows = (await settingsResponse.json()).data || [];
  expect(settingsRows.length).toBeLessThanOrEqual(1);
  for (const row of settingsRows) {
    expect(Object.keys(row).sort()).toEqual(['is_json','setting_key','setting_value']);
  }

  const mediaResponse = await page.request.get(
    `${baseUrl}/api/index.php/media?view=hero-picker`
  );
  expect(mediaResponse.ok()).toBeTruthy();
  const mediaRows = (await mediaResponse.json()).data || [];
  expect(mediaRows.length).toBeGreaterThan(0);
  for (const row of mediaRows) {
    expect(['image','video']).toContain(row.type);
    expect(Object.keys(row).sort()).toEqual(['file_path','id','status','title','type']);
  }
});

test('Hero Slider settings reject broken local media and accept canonical or HTTPS media', async ({ page }) => {
  const auth = await login(page);
  const original = await readSetting(page);
  const headers = {'X-CSRF-Token':auth.csrf};
  const slide = overrides => ({
    enabled:true,
    name:'CI HERO',
    mediaType:'image',
    desktopSrc:'/uploads/ci/hero-integrity.jpg',
    mobileSrc:'',
    poster:'',
    layers:[],
    ...overrides,
  });

  const restore = async () => {
    if (original === null) {
      const response = await page.request.delete(
        `${baseUrl}/api/index.php/settings?key=${encodeURIComponent(settingKey)}`,
        {headers},
      );
      expect(response.ok()).toBeTruthy();
      return;
    }
    const response = await page.request.post(`${baseUrl}/api/index.php/settings`, {
      headers,
      data:{
        setting_key:settingKey,
        setting_value:String(original.setting_value ?? ''),
        is_json:Number(original.is_json ?? 0),
      },
    });
    expect(response.ok()).toBeTruthy();
  };

  try {
    const unregistered = await saveSetting(page, auth.csrf, {
      enabled:true,
      slides:[slide({desktopSrc:'/uploads/ci/not-registered.jpg'})],
    });
    expect(unregistered.status()).toBe(422);
    expect(await unregistered.json()).toMatchObject({
      error:'HERO_SLIDER_MEDIA_NOT_REGISTERED',
      field:'slides.0.desktopSrc',
    });

    const missingFile = await saveSetting(page, auth.csrf, {
      enabled:true,
      slides:[slide({desktopSrc:'/uploads/ci/hero-missing.jpg'})],
    });
    expect(missingFile.status()).toBe(422);
    expect(await missingFile.json()).toMatchObject({
      error:'HERO_SLIDER_MEDIA_FILE_MISSING',
      field:'slides.0.desktopSrc',
    });

    const wrongType = await saveSetting(page, auth.csrf, {
      enabled:true,
      slides:[slide({mediaType:'video'})],
    });
    expect(wrongType.status()).toBe(422);
    expect(await wrongType.json()).toMatchObject({
      error:'HERO_SLIDER_MEDIA_TYPE_MISMATCH',
      field:'slides.0.desktopSrc',
    });

    const validLocal = await saveSetting(page, auth.csrf, {
      enabled:true,
      slides:[slide({})],
    });
    expect(validLocal.ok()).toBeTruthy();

    const persistedLocal = await readSetting(page);
    expect(JSON.parse(String(persistedLocal?.setting_value || '{}')))
      .toMatchObject({enabled:true,slides:[{desktopSrc:'/uploads/ci/hero-integrity.jpg'}]});

    const validExternal = await saveSetting(page, auth.csrf, {
      enabled:true,
      slides:[slide({desktopSrc:'https://cdn.example.test/hero.jpg'})],
    });
    expect(validExternal.ok()).toBeTruthy();
  } finally {
    await restore();
  }
});
