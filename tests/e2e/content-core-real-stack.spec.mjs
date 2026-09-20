    window.__staleDashboardNavigation = window.go('dashboard');
  });
  await staleDashboardStarted;

  await page.getByRole('button', {name:'MEDIA LIBRARY', exact:true}).click();
  await expectMediaMounted(page);
  await expect(page.locator('#media-grid')).toBeVisible();
  await expect(page.getByRole('button', {name:/REGISTER EXTERNAL/i})).toHaveCount(0);

  releaseStaleDashboard();
  await page.evaluate(() => window.__staleDashboardNavigation);
  await expectMediaMounted(page, 5_000);
  await expect(page.locator('.main .top h1')).toHaveText('MEDIA');

  await page.evaluate(() => window.go('sets'));
  await expect(page.locator('.main .top h1')).toHaveText('SETS');
  await page.getByRole('button', {name:'MEDIA', exact:true}).click();
  await expectMediaMounted(page);

  await page.goto(`${baseUrl}/discadmin/?module=media`, {waitUntil:'domcontentloaded'});
  await expectMediaMounted(page);
  await expect(page.locator('.main .top h1')).toHaveText('MEDIA');