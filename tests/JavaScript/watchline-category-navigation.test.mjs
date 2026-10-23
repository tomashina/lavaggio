import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../../public/front-theme/scripts/watchline-category-navigation.js', import.meta.url), 'utf8');

const setup = (finePointer = true, surface = 'desktop', desktopViewport = true) => {
    const makeNode = () => ({
        dataset: {}, attributes: {}, listeners: {}, children: [], parent: null, hidden: false,
        classList: { values: new Set(), add(value) { this.values.add(value); }, remove(value) { this.values.delete(value); } },
        addEventListener(type, callback) { (this.listeners[type] ??= []).push(callback); },
        setAttribute(name, value) { this.attributes[name] = value; },
        contains(target) { return target === this || this.children.some((child) => child.contains(target)); },
        matches() { return false; },
        focus() { document.activeElement = this; },
        emit(type, extra = {}) {
            const event = { target: this, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...extra };
            (this.listeners[type] ?? []).forEach((callback) => callback(event));
            return event;
        },
    });
    const entries = Array.from({ length: 3 }, () => {
        const entry = makeNode();
        entry.toggle = makeNode();
        entry.link = makeNode();
        entry.panel = makeNode();
        entry.panel.hidden = true;
        entry.panel.links = Array.from({ length: 3 }, makeNode);
        entry.panel.children = entry.panel.links;
        entry.children = [entry.toggle, entry.link, entry.panel];
        entry.querySelector = (selector) => selector.includes('toggle') ? entry.toggle : entry.panel;
        entry.closest = () => ({ dataset: { watchlineCategoryNav: surface } });
        entry.panel.querySelectorAll = () => entry.panel.links;
        return entry;
    });
    const document = {
        body: { dataset: {} }, activeElement: null, readyState: 'complete', listeners: {},
        querySelectorAll: (selector) => selector === '[data-watchline-nav-item]' ? entries : [],
        addEventListener(type, callback) { this.listeners[type] = callback; },
    };
    const window = {
        listeners: {}, setTimeout: (callback) => callback(),
        matchMedia: (query) => ({ matches: query.includes('hover') ? finePointer : desktopViewport, addEventListener() {} }),
        addEventListener(type, callback) { this.listeners[type] = callback; },
    };
    vm.runInNewContext(script, { document, window });
    return { entries, document, window };
};

test('touch disclosures toggle independently, update accessible state and close on an outside tap', () => {
    const { entries, document } = setup(false);
    entries[0].toggle.emit('click');
    assert.equal(entries[0].panel.hidden, false);
    assert.equal(entries[0].toggle.attributes['aria-expanded'], 'true');
    entries[1].toggle.emit('click');
    assert.equal(entries[0].panel.hidden, true);
    assert.equal(entries[1].panel.hidden, false);
    document.listeners.pointerdown({ target: {} });
    assert.equal(entries[1].panel.hidden, true);
    assert.equal(entries[1].toggle.attributes['aria-expanded'], 'false');
});

test('keyboard users can open a category, move through links and return focus with Escape', () => {
    const { entries, document } = setup();
    const entry = entries[0];
    const open = entry.emit('keydown', { key: 'ArrowDown', target: entry.link });
    assert.equal(open.defaultPrevented, true);
    assert.equal(document.activeElement, entry.panel.links[0]);
    entry.emit('keydown', { key: 'ArrowDown', target: entry.panel.links[0] });
    assert.equal(document.activeElement, entry.panel.links[1]);
    entry.emit('keydown', { key: 'End', target: entry.panel.links[1] });
    assert.equal(document.activeElement, entry.panel.links[2]);
    entry.emit('keydown', { key: 'Escape', target: entry.panel.links[2] });
    assert.equal(entry.panel.hidden, true);
    assert.equal(document.activeElement, entry.toggle);
});

test('mouse hover opens dropdowns while touch hover does not, and a restored page closes stale menus', () => {
    const { entries, window } = setup();
    entries[0].emit('pointerenter', { pointerType: 'touch' });
    assert.equal(entries[0].panel.hidden, true);
    entries[0].emit('pointerenter', { pointerType: 'mouse' });
    assert.equal(entries[0].panel.hidden, false);
    entries[1].emit('pointerenter', { pointerType: 'mouse' });
    assert.equal(entries[0].panel.hidden, true);
    assert.equal(entries[1].panel.hidden, false);
    window.listeners.pageshow();
    assert.equal(entries[1].panel.hidden, true);
});

test('a fine-pointer mouse opens the mobile category on its first click without hover cancelling it', () => {
    const { entries } = setup(true, 'mobile', false);
    const entry = entries[0];
    entry.emit('pointerenter', { pointerType: 'mouse' });
    assert.equal(entry.panel.hidden, true);
    entry.toggle.emit('click');
    assert.equal(entry.panel.hidden, false);
    assert.equal(entry.toggle.attributes['aria-expanded'], 'true');
    entry.emit('pointerleave', { pointerType: 'mouse' });
    assert.equal(entry.panel.hidden, false);
    entry.toggle.emit('click');
    assert.equal(entry.panel.hidden, true);
});
