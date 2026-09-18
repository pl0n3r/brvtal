import { expect } from '@playwright/test';

export async function openDiscadminModule(page, baseUrl, moduleName, rootTestId) {
  await page.goto(`${baseUrl}/discadmin/`, {waitUntil:'domcontentloaded'});
  await page.waitForFunction(() => Boolean(window.state?.authed), null, {timeout:10_000});
  await page.evaluate(module => window.go?.(module), moduleName);
  await expect(page.getByTestId(rootTestId)).toBeVisible({timeout:10_000});
}
