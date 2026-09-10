'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { JSDOM } = require('jsdom');

const { setupQuickSale, enhanceSelects } = require('../assets/js/admin-pilotage.js');

const FORM = `
<div class="luziapi-pilotage">
  <form data-quick-sale>
    <select data-client-picker>
      <option value="">— Nouveau client —</option>
      <option value="a" data-name="Alice" data-email="alice@example.test" data-phone="0600000001" data-city="Luzillé">Alice</option>
      <option value="b" data-name="Bob" data-email="bob@example.test" data-phone="0600000002" data-city="Bléré">Bob</option>
    </select>
    <input type="text" name="customer_name">
    <input type="email" name="email" data-quick-email>
    <input type="tel" name="phone">
    <input type="checkbox" name="send_email" data-send-email disabled>
    <select data-fulfillment>
      <option value="immediate">Immédiate</option>
      <option value="pickup">Retrait</option>
      <option value="delivery">Livraison</option>
    </select>
    <section data-delivery-address hidden>
      <input type="text" name="address">
      <input type="text" name="postcode">
      <input type="text" name="city">
    </section>
    <div class="luziapi-pilotage__submit-bar"><button type="submit">Créer la vente</button></div>
  </form>
</div>`;

function build() {
    const dom = new JSDOM(FORM);
    const { document } = dom.window;
    const form = document.querySelector('[data-quick-sale]');
    const q = (sel) => form.querySelector(sel);
    const fire = (el, type) => el.dispatchEvent(new dom.window.Event(type, { bubbles: true }));

    return { dom, document, form, q, fire };
}

test('choisir un client préremplit nom, e-mail, téléphone et ville', () => {
    const { form, q, fire } = build();
    setupQuickSale(form);

    const picker = q('[data-client-picker]');
    picker.value = 'b';
    fire(picker, 'change');

    assert.equal(q('[name="customer_name"]').value, 'Bob');
    assert.equal(q('[data-quick-email]').value, 'bob@example.test');
    assert.equal(q('[name="phone"]').value, '0600000002');
    assert.equal(q('[name="city"]').value, 'Bléré');
    assert.equal(q('[data-send-email]').disabled, false, 'l’envoi d’e-mail s’active quand un e-mail est présent');
});

test('revenir à « Nouveau client » vide les champs et coupe l’e-mail', () => {
    const { form, q, fire } = build();
    setupQuickSale(form);
    const picker = q('[data-client-picker]');

    picker.value = 'a';
    fire(picker, 'change');
    picker.value = '';
    fire(picker, 'change');

    assert.equal(q('[name="customer_name"]').value, '');
    assert.equal(q('[data-quick-email]').value, '');
    assert.equal(q('[name="phone"]').value, '');
    assert.equal(q('[data-send-email]').disabled, true);
    assert.equal(q('[data-send-email]').checked, false);
});

test('le mode « Livraison » révèle l’adresse et la rend obligatoire', () => {
    const { form, q, fire } = build();
    setupQuickSale(form);
    const fulfillment = q('[data-fulfillment]');
    const delivery = q('[data-delivery-address]');

    fulfillment.value = 'delivery';
    fire(fulfillment, 'change');
    assert.equal(delivery.hidden, false);
    assert.equal(q('[name="address"]').required, true);

    fulfillment.value = 'immediate';
    fire(fulfillment, 'change');
    assert.equal(delivery.hidden, true);
    assert.equal(q('[name="address"]').required, false);
});

test('le pont selectWoo rejoue un « change » natif qui déclenche le préremplissage', () => {
    const { document, form, q } = build();
    setupQuickSale(form);

    // Faux jQuery/selectWoo : capture le callback d’évènement select2 par élément.
    const jq = (el) => ({
        selectWoo() { return this; },
        on(_events, cb) { el.__select2cb = cb; return this; },
    });
    jq.fn = { selectWoo: true };

    enhanceSelects(document, jq);

    const picker = q('[data-client-picker]');
    picker.value = 'a';
    picker.__select2cb(); // simule une sélection selectWoo

    assert.equal(q('[name="customer_name"]').value, 'Alice');
    assert.equal(q('[data-quick-email]').value, 'alice@example.test');
});

test('sans jQuery/selectWoo, enhanceSelects ne fait rien (repli natif)', () => {
    const { document, q } = build();
    // Ne doit pas lever et ne doit rien enrichir.
    enhanceSelects(document, undefined);
    assert.equal(q('[data-client-picker]').__select2cb, undefined);
});
