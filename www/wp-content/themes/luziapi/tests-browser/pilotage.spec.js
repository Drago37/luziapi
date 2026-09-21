// @ts-check
const { test, expect } = require('@playwright/test');

const PAGE = 'luziapi-pilotage';

/**
 * Onglets du tableau de pilotage (source : PilotageTabs::links()). Le tableau de
 * bord est l'onglet sans paramètre `tab`. « Commandes » pointe vers l'écran natif
 * WooCommerce (wc-orders) : hors périmètre de ce smoke.
 */
const TABS = [
  { key: '', label: 'Vue d’ensemble' },
  { key: 'receipts', label: 'Recettes' },
  { key: 'tax-declaration', label: 'Déclaration fiscale' },
  { key: 'customers', label: 'Clients' },
  { key: 'subscribers', label: 'Abonnés' },
  { key: 'loyalty', label: 'Fidélité' },
  { key: 'products', label: 'Produits' },
  { key: 'inventory', label: 'Stocks et lots' },
  { key: 'quick-sale', label: 'Vente' },
  { key: 'activity', label: 'Journal d’activité' },
];

for (const tab of TABS) {
  test(`la vue « ${tab.label} » charge sans erreur`, async ({ page }) => {
    /** @type {string[]} */
    const jsErrors = [];
    page.on('pageerror', (error) => jsErrors.push(error.message));

    const url = tab.key
      ? `/wp-admin/admin.php?page=${PAGE}&tab=${tab.key}`
      : `/wp-admin/admin.php?page=${PAGE}`;
    const response = await page.goto(url);

    // Réponse HTTP saine (pas de 500).
    expect(response?.status(), 'statut HTTP').toBeLessThan(400);

    // Pas d'erreur fatale PHP rendue dans la page.
    const body = await page.locator('body').innerText();
    expect(body).not.toContain('There has been a critical error');
    expect(body).not.toContain('Fatal error');

    // La vue du pilotage et sa navigation sont bien rendues.
    await expect(page.locator('.luziapi-pilotage')).toBeVisible();
    await expect(page.locator('nav.nav-tab-wrapper')).toBeVisible();

    // Aucune exception JavaScript non capturée.
    expect(jsErrors, 'exceptions JS').toEqual([]);
  });
}

test('la Vente présente le formulaire de création (garde anti-double-clic testée en unitaire jsdom)', async ({ page }) => {
  await page.goto(`/wp-admin/admin.php?page=${PAGE}&tab=quick-sale`);
  await expect(page.locator('form.luziapi-pilotage__quick-sale, form#luziapi-quick-sale, .luziapi-pilotage form').first()).toBeVisible();
  await expect(page.locator('button[type="submit"], input[type="submit"]').first()).toBeVisible();
});
