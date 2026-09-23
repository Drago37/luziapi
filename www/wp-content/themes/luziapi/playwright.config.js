// @ts-check
const { defineConfig, devices } = require('@playwright/test');

/**
 * Smoke de navigation du tableau de pilotage (admin WordPress) via un navigateur
 * headless. Cible le WP local/CI (http://localhost:8080 par défaut). Le login
 * admin est fait une fois par global-setup et réutilisé via storageState.
 */
module.exports = defineConfig({
  testDir: './tests-browser',
  globalSetup: require.resolve('./tests-browser/global-setup.js'),
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: 'list',
  use: {
    baseURL: process.env.WP_URL || 'http://localhost:8080',
    storageState: 'tests-browser/.auth/admin.json',
    trace: 'on-first-retry',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
});
