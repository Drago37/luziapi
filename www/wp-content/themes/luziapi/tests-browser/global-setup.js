// @ts-check
const { chromium } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

/**
 * Se connecte une fois à l'admin WordPress (admin/admin, créé par `make
 * wp-install`) et enregistre l'état de session pour tous les tests.
 */
module.exports = async () => {
  const baseURL = process.env.WP_URL || 'http://localhost:8080';
  const user = process.env.WP_ADMIN_USER || 'admin';
  const pass = process.env.WP_ADMIN_PASS || 'admin';
  const storagePath = path.join(__dirname, '.auth', 'admin.json');

  const browser = await chromium.launch();
  const page = await browser.newPage({ baseURL });

  await page.goto('/wp-login.php');
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await page.click('#wp-submit');
  await page.waitForLoadState('networkidle');
  // Le thème redirige la connexion vers l'accueil ; on rejoint l'admin et on
  // vérifie la session via le menu (sinon WordPress renverrait au login).
  await page.goto('/wp-admin/');
  await page.waitForSelector('#adminmenu', { timeout: 15000 });

  fs.mkdirSync(path.dirname(storagePath), { recursive: true });
  await page.context().storageState({ path: storagePath });
  await browser.close();
};
