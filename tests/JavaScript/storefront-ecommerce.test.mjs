import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/front-theme/scripts/shop-analytics.js', import.meta.url), 'utf8');

function storefront(storage = new Map(), cart) {
    const events = [];
    const document = new EventTarget();
    const form = new EventTarget();
    form.dataset = { ga4Cart: JSON.stringify(cart) };
    const choices = {};
    form.querySelector = selector => choices[selector.match(/name="(.*?)"/)[1]];
    document.querySelector = () => cart ? form : null;
    const window = {
        gtag: (command, name, params) => events.push({ name, params }),
        sessionStorage: { getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value) },
    };
    vm.runInNewContext(source, { document, window });
    function consent(granted) {
        window.canTrackAnalytics = () => granted;
        document.dispatchEvent(new Event('shop:consent'));
    }
    return { window, document, form, choices, consent, events };
}

test('purchase waits for consent and fires only once when the receipt is reopened', () => {
    const storage = new Map();
    const params = { transaction_id: 'LOCAL-1', currency: 'EUR', value: 12 };
    const first = storefront(storage);
    first.window.ShopAnalytics.trackOnce('purchase:LOCAL-1', 'purchase', params);
    first.consent(false);
    assert.equal(first.events.length, 0);
    first.consent(true);
    first.consent(true);
    assert.deepEqual(first.events, [{ name: 'purchase', params }]);
    const refresh = storefront(storage);
    refresh.consent(true);
    refresh.window.ShopAnalytics.trackOnce('purchase:LOCAL-1', 'purchase', params);
    assert.equal(refresh.events.length, 0);
});

test('page views follow consent and distinct visits to the same product count separately', () => {
    const storage = new Map();
    for (let i = 0; i < 2; i++) {
        const page = storefront(storage);
        page.window.ShopAnalytics.trackPage('view_item', { currency: 'EUR', items: [] });
        assert.equal(page.events.length, 0);
        page.consent(true);
        page.consent(true);
        assert.equal(page.events.length, 1);
    }
});

test('checkout measures the refreshed cart and default shipping and payment on acceptance', () => {
    const payload = { currency: 'EUR', value: 15, items: [{ item_id: 'LOCAL-1', price: 15, quantity: 1 }] };
    const page = storefront(new Map(), payload);
    page.consent(true);
    page.document.dispatchEvent(new Event('DOMContentLoaded'));
    page.form.dataset.ga4Cart = JSON.stringify({ ...payload, value: 12, items: [{ item_id: 'LOCAL-1', price: 12, quantity: 1 }] });
    for (const [name, value] of [['shipping_method_code', 'gls'], ['payment_method_code', 'bank']]) {
        page.choices[name] = { name, value, checked: true };
    }
    page.form.dispatchEvent(new Event('submit'));
    assert.deepEqual(page.events.map(event => event.name), ['begin_checkout']);
    page.form.dispatchEvent(new Event('checkout:accepted'));
    page.form.dispatchEvent(new Event('checkout:accepted'));
    assert.deepEqual(page.events.map(event => event.name), ['begin_checkout', 'add_shipping_info', 'add_payment_info']);
    assert.equal(page.events[1].params.shipping_tier, 'gls');
    assert.equal(page.events[2].params.payment_type, 'bank');
    assert.equal(page.events[2].params.value, 12);
});
