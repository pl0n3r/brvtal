import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 30000,
  fullyParallel: false,
  reporter: [['list']],
  use: {
    baseURL: 'http://127.0.0.1:4173',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure'
  },
  projects: [
    {
      name: 'chromium',
      testIgnore: /discadmin-password-recovery-real-stack\.spec\.mjs/,
      use: { ...devices['Desktop Chrome'] }
    },
    {
      name: 'webkit-totp',
      testMatch: /discadmin-totp-login\.spec\.mjs/,
      use: { ...devices['Desktop Safari'] }
    }
  ]
});
