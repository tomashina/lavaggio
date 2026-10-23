import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const template = readFileSync(new URL('../../resources/views/front/desktop/layouts/store.blade.php', import.meta.url), 'utf8');
const loader = template.match(/            const loadNewsletterCaptcha = function \(\) \{[\s\S]*?\n            \};/)[0]
    .replace('@json($newsletterCaptchaSiteKey)', '"fixture-site-key"');
const refresh = template.match(/            const refreshNewsletterRecaptcha = function \(\) \{[\s\S]*?\n            \};/)[0];

function setup() {
    const scripts = [];
    const token = { value: '' };
    const state = { executions: 0 };
    const context = {
        window: {},
        document: {
            createElement: () => ({ remove() { this.removed = true; } }),
            head: { appendChild(script) { scripts.push(script); } },
        },
        newsletterForm: {
            dataset: { recaptchaSiteKey: 'fixture-site-key', recaptchaAction: 'newsletter_footer' },
            querySelector: () => token,
        },
    };
    vm.runInNewContext(`let newsletterCaptchaReady = null; ${loader} ${refresh}
        globalThis.load = loadNewsletterCaptcha; globalThis.refresh = refreshNewsletterRecaptcha;`, context);
    const ready = () => {
        context.window.grecaptcha = {
            ready(callback) { callback(); },
            execute(key, { action }) {
                assert.equal(key, 'fixture-site-key');
                assert.equal(action, 'newsletter_footer');
                state.executions++;
                return Promise.resolve('verified-token');
            },
        };
        scripts.at(-1).onload();
    };
    return { context, scripts, token, state, ready };
}

test('initial page does not contact Google and repeated interaction shares one pending script', async () => {
    const page = setup();
    assert.equal(page.scripts.length, 0);
    const first = page.context.load();
    const second = page.context.load();
    assert.equal(first, second);
    assert.equal(page.scripts.length, 1);
    assert.equal(page.scripts[0].src, 'https://www.google.com/recaptcha/api.js?render=fixture-site-key');
    page.ready();
    await first;
});

test('immediate submission waits for the script and obtains a fresh token before it completes', async () => {
    const page = setup();
    const submitted = page.context.refresh();
    assert.equal(page.scripts.length, 1);
    assert.equal(page.state.executions, 0);
    assert.equal(page.token.value, '');
    page.ready();
    await submitted;
    assert.equal(page.state.executions, 1);
    assert.equal(page.token.value, 'verified-token');
    await page.context.refresh();
    assert.equal(page.state.executions, 2);
    assert.equal(page.scripts.length, 1);
});

test('a failed connection can retry and never creates an invented verification token', async () => {
    const page = setup();
    const failed = page.context.refresh();
    page.scripts[0].onerror();
    await failed;
    assert.equal(page.token.value, '');
    assert.equal(page.scripts[0].removed, true);
    const retried = page.context.refresh();
    assert.equal(page.scripts.length, 2);
    page.ready();
    await retried;
    assert.equal(page.token.value, 'verified-token');
});
