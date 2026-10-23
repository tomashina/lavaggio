import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/front-theme/scripts/product-card-cart-modal.js', import.meta.url), 'utf8');

function page({ native = true, options = false, selected = false } = {}) {
    const handlers = new Map();
    const classes = new Set(['hidden']);
    let requests = 0;
    let tracked = 0;
    class Form {
        dataset = {};
        action = '/kosarica/stavke';
        closest(selector) {
            if (selector === '[data-product-card-form]') return this;
            if (selector === '[data-cart-native-submits]') return native ? {} : null;
            return null;
        }
        querySelectorAll() { return options ? [{}] : []; }
        querySelector(selector) {
            if (selector === '[data-option-error]') return { classList: { add: (name) => classes.add(name), remove: (name) => classes.delete(name) } };
            if (selector === 'input[name="product_option_value_id"]:checked') return selected ? { closest: () => null, id: '' } : null;
            if (selector === 'input[name="quantity"]') return { value: '2' };
            return null;
        }
    }
    const form = new Form();
    const document = {
        readyState: 'complete', querySelectorAll: () => [], querySelector: () => null,
        addEventListener: (name, handler) => handlers.set(name, handler), dispatchEvent() {},
    };
    const window = { ShopAnalytics: { trackAddToCartFromForm: () => tracked++ } };
    vm.runInNewContext(source, {
        document, window, HTMLFormElement: Form, FormData: class {}, CustomEvent: class {},
        fetch: async () => { requests++; return { ok: true, json: async () => ({ ok: true, summary: { item_qty: 3 } }) }; },
    });
    let prevented = false;
    handlers.get('submit')({ target: form, preventDefault: () => { prevented = true; } });
    return { prevented, requests: () => requests, tracked: () => tracked, errorVisible: !classes.has('hidden') };
}

test('cart recommendations use native POST so the full cart refreshes', () => {
    const result = page();
    assert.equal(result.prevented, false);
    assert.equal(result.requests(), 0);
    assert.equal(result.tracked(), 1);
});

test('a recommendation cannot submit before its required variant is selected', () => {
    const result = page({ options: true });
    assert.equal(result.prevented, true);
    assert.equal(result.errorVisible, true);
    assert.equal(result.requests(), 0);
    assert.equal(result.tracked(), 0);
    assert.equal(page({ options: true, selected: true }).prevented, false);
});

test('normal catalog cards keep the AJAX confirmation flow', async () => {
    const result = page({ native: false });
    assert.equal(result.prevented, true);
    assert.equal(result.requests(), 1);
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(result.tracked(), 1);
});
