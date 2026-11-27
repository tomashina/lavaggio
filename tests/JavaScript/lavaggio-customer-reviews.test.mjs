import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const template = readFileSync(new URL('../../resources/views/front/partials/google-customer-reviews.blade.php', import.meta.url), 'utf8');
const script = template.match(/<script>([\s\S]*?)<\/script>/)[1].replace('@js($invitation)', 'order');

function receipt(marketing) {
    const events = new Map();
    const scripts = [];
    const rendered = [];
    const elements = new Map();
    const window = { cookieMarketingAllowed: marketing };
    const order = { order_id: 'TEST' };
    const document = {
        head: { appendChild(element) { scripts.push(element); } },
        createElement() { return { remove() {} }; },
        getElementById(id) {
            if (!elements.has(id)) elements.set(id, {
                addEventListener(name, callback) { events.set(name, callback); }
            });
            return elements.get(id);
        },
        addEventListener(name, callback) { events.set(name, callback); }
    };
    vm.runInNewContext(script, { document, window, order });
    function ready() {
        window.gapi = {
            load(name, callback) { assert.equal(name, 'surveyoptin'); callback(); },
            surveyoptin: { render(data) { rendered.push(data.order_id); } }
        };
        window.renderOptIn();
    }
    return { events, scripts, rendered, window, ready };
}

test('the review invitation can be opened without marketing consent, once per page', () => {
    const page = receipt(false);
    page.events.get('shop:consent')();
    assert.equal(page.scripts.length, 0);
    page.events.get('click')();
    assert.equal(page.scripts[0].src, 'https://apis.google.com/js/platform.js?onload=renderOptIn');
    page.ready();
    page.events.get('click')();
    assert.deepEqual(page.rendered, ['TEST']);
    assert.equal(page.scripts.length, 1);
});

test('withdrawn consent stops automatic rendering before Google finishes loading', () => {
    const page = receipt(true);
    assert.equal(page.scripts.length, 1);
    page.window.cookieMarketingAllowed = false;
    page.ready();
    assert.deepEqual(page.rendered, []);
    page.window.cookieMarketingAllowed = true;
    page.events.get('shop:consent')();
    assert.deepEqual(page.rendered, ['TEST']);
});
