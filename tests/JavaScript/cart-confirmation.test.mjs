import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../../public/front-theme/scripts/cart-modal.js', import.meta.url), 'utf8');

function page() {
    let focused = null;
    let nextTimer = 0;
    const timers = new Map();
    let document;
    class Element {
        constructor() {
            this.dataset = {};
            this.attributes = new Map();
            this.nodes = new Map();
            this.listeners = new Map();
            const classes = new Set();
            this.classList = {
                add: (...names) => names.forEach((name) => classes.add(name)),
                remove: (...names) => names.forEach((name) => classes.delete(name)),
                contains: (name) => classes.has(name),
            };
            Object.defineProperty(this, 'className', {
                set: (value) => { classes.clear(); value.split(' ').forEach((name) => classes.add(name)); },
            });
        }
        setAttribute(name, value) { this.attributes.set(name, value); }
        removeAttribute(name) { this.attributes.delete(name); }
        querySelector(selector) {
            if (!this.nodes.has(selector)) this.nodes.set(selector, new Element());
            return this.nodes.get(selector);
        }
        addEventListener(type, handler) { this.listeners.set(type, handler); }
        dispatch(type, event) { this.listeners.get(type)?.(event); }
        focus() { focused = this; document.activeElement = this; }
    }
    const body = new Element();
    body.children = [];
    body.appendChild = (element) => body.children.push(element);
    document = { body, createElement: () => new Element(), activeElement: new Element() };
    const window = {
        setTimeout(callback) { timers.set(++nextTimer, callback); return nextTimer; },
        clearTimeout(id) { timers.delete(id); },
        requestAnimationFrame(callback) { callback(); return 1; },
        cancelAnimationFrame() {},
    };
    vm.runInNewContext(script, { window, document, HTMLElement: Element });
    return { window, document, focused: () => focused, timers };
}

test('B2B confirmation is a polite notice without stealing focus or blocking scrolling', () => {
    const { window, document, focused, timers } = page();
    window.TermolCartModal.confirm({ dataset: {} }, '', 1, { confirmation: 'notice', message: 'Artikl je dodan u košaricu.' });
    assert.equal(document.body.children.length, 1);
    const notice = document.body.children[0];
    assert.equal(notice.attributes.get('role'), 'status');
    assert.equal(notice.attributes.get('aria-live'), 'polite');
    assert.equal(notice.textContent, 'Artikl je dodan u košaricu.');
    assert.equal(notice.hidden, false);
    assert.equal(document.body.classList.contains('overflow-hidden'), false);
    assert.equal(focused(), null);
    [...timers.values()][0]();
    assert.equal(notice.hidden, true);
});

test('consecutive additions reuse the notice and restart its dismissal timer', () => {
    const { window, document, timers } = page();
    window.TermolCartModal.confirm({ dataset: {} }, '', 1, { confirmation: 'notice', message: 'First' });
    const firstTimer = [...timers.keys()][0];
    window.TermolCartModal.confirm({ dataset: {} }, '', 2, { confirmation: 'notice', message: '<b>Second</b>' });
    assert.equal(document.body.children.length, 1);
    assert.equal(document.body.children[0].textContent, '<b>Second</b>');
    assert.equal(timers.size, 1);
    assert.equal(timers.has(firstTimer), false);
});

test('B2C confirmation still opens the product dialog and can close it', () => {
    const { window, document, focused } = page();
    const trigger = document.activeElement;
    window.TermolCartModal.confirm({ dataset: { productName: 'Lamp', modalTitle: 'Added', cartUrl: '/cart' } }, '', 2, { confirmation: 'modal' });
    const modal = document.body.children[0];
    assert.equal(modal.classList.contains('product-cart-modal'), true);
    assert.equal(modal.classList.contains('hidden'), false);
    assert.equal(modal.querySelector('[data-cart-modal-name]').textContent, 'Lamp');
    assert.equal(modal.querySelector('[data-cart-modal-qty]').textContent, '2');
    assert.equal(document.body.classList.contains('overflow-hidden'), true);
    assert.notEqual(focused(), null);
    window.TermolCartModal.close();
    assert.equal(modal.classList.contains('hidden'), true);
    assert.equal(document.body.classList.contains('overflow-hidden'), false);
    assert.equal(focused(), trigger);
});

test('the dialog shows the selected product, image, option and submitted quantity', () => {
    const { window, document } = page();
    window.TermolCartModal.confirm({ dataset: {
        productName: 'Kocke za djecu', productImage: '/kocke.jpg', modalTitle: 'Dodano u košaricu',
        modalContinue: 'Nastavi s kupnjom', modalGoCart: 'Dovrši kupnju', cartUrl: '/kosarica',
    } }, '300 dijelova', 120, { confirmation: 'modal' });
    const modal = document.body.children[0];
    assert.equal(modal.querySelector('[data-cart-modal-image]').src, '/kocke.jpg');
    assert.equal(modal.querySelector('[data-cart-modal-image]').alt, 'Kocke za djecu');
    assert.equal(modal.querySelector('[data-cart-modal-option]').textContent, '300 dijelova');
    assert.equal(modal.querySelector('[data-cart-modal-qty]').textContent, '120');
    assert.equal(modal.querySelector('[data-cart-modal-cart]').attributes.get('href'), '/kosarica');
    assert.equal(modal.querySelector('[data-cart-modal-continue-label]').textContent, 'Nastavi s kupnjom');
});

test('Tab stays within the dialog and Escape closes it and returns focus to the trigger', () => {
    const { window, document, focused } = page();
    const trigger = document.activeElement;
    window.TermolCartModal.confirm({ dataset: {} }, '', 1, { confirmation: 'modal' });
    const modal = document.body.children[0];
    const close = modal.querySelector('[data-cart-modal-close]');
    const cart = modal.querySelector('[data-cart-modal-cart]');
    let prevented = 0;
    modal.dispatch('keydown', { key: 'Tab', shiftKey: true, preventDefault() { prevented++; } });
    assert.equal(focused(), cart);
    modal.dispatch('keydown', { key: 'Tab', shiftKey: false, preventDefault() { prevented++; } });
    assert.equal(focused(), close);
    modal.dispatch('keydown', { key: 'Escape', preventDefault() { prevented++; } });
    assert.equal(prevented, 3);
    assert.equal(modal.classList.contains('hidden'), true);
    assert.equal(focused(), trigger);
    assert.equal(document.body.classList.contains('overflow-hidden'), false);
});
