import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../../public/front-theme/scripts/lavaggio-catalog-navigation.js', import.meta.url), 'utf8');

const setup = () => {
    const makeNode = () => ({
        dataset: {}, attributes: {}, listeners: {}, children: [], hidden: false,
        classList: {
            values: new Set(),
            toggle(value, enabled) { enabled ? this.values.add(value) : this.values.delete(value); },
        },
        addEventListener(type, callback) { (this.listeners[type] ??= []).push(callback); },
        setAttribute(name, value) { this.attributes[name] = value; },
        contains(target) { return target === this || this.children.some((child) => child.contains(target)); },
        matches() { return false; },
        focus() { document.activeElement = this; this.emit('focus'); },
        emit(type, extra = {}) {
            const event = { target: this, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...extra };
            (this.listeners[type] ?? []).forEach((callback) => callback(event));
            return event;
        },
    });
    const trigger = makeNode();
    const roots = Array.from({ length: 3 }, makeNode);
    const panels = roots.map((root, index) => {
        const panel = makeNode();
        panel.id = `category-${index}`;
        panel.hidden = index !== 0;
        root.dataset.panelId = panel.id;
        panel.children = Array.from({ length: 2 }, makeNode);
        panel.querySelectorAll = () => panel.children;
        return panel;
    });
    const menu = makeNode();
    menu.hidden = true;
    menu.children = [...roots, ...panels];
    menu.querySelectorAll = (selector) => selector.includes('-root]') ? roots : panels;
    const catalog = makeNode();
    catalog.children = [trigger, menu];
    catalog.querySelector = (selector) => selector.includes('-trigger]') ? trigger : menu;
    const document = {
        readyState: 'complete', activeElement: null, listeners: {},
        querySelector: (selector) => selector === '[data-lavaggio-catalog]' ? catalog : null,
        querySelectorAll: () => [],
        addEventListener(type, callback) { this.listeners[type] = callback; },
    };
    const desktop = {
        matches: true,
        addEventListener(type, callback) { this.change = callback; },
    };
    const timers = new Map();
    const window = {
        listeners: {},
        setTimeout(callback) { const id = Symbol(); timers.set(id, callback); return id; },
        clearTimeout(id) { timers.delete(id); },
        matchMedia: (query) => query.includes('min-width') ? desktop : { matches: true },
        addEventListener(type, callback) { this.listeners[type] = callback; },
    };
    vm.runInNewContext(script, { document, window });
    return { catalog, trigger, menu, roots, panels, document, window, desktop };
};

test('click and outside tap toggle the catalog with accessible expanded state', () => {
    const { trigger, menu, document } = setup();
    trigger.emit('click');
    assert.equal(menu.hidden, false);
    assert.equal(trigger.attributes['aria-expanded'], 'true');
    document.listeners.pointerdown({ target: {} });
    assert.equal(menu.hidden, true);
    assert.equal(trigger.attributes['aria-expanded'], 'false');
    trigger.emit('click');
    trigger.emit('click');
    assert.equal(menu.hidden, true);
});

test('hover and focus switch the selected category without exposing other panels', () => {
    const { catalog, trigger, menu, roots, panels } = setup();
    catalog.emit('pointerenter', { pointerType: 'mouse' });
    assert.equal(menu.hidden, false);
    trigger.emit('click');
    assert.equal(menu.hidden, false);
    roots[1].emit('pointerenter', { pointerType: 'mouse' });
    assert.deepEqual(panels.map((panel) => panel.hidden), [true, false, true]);
    assert.equal(roots[1].attributes['aria-expanded'], 'true');
    roots[2].focus();
    assert.deepEqual(panels.map((panel) => panel.hidden), [true, true, false]);
    assert.equal(roots[1].attributes['aria-expanded'], 'false');
    assert.equal(trigger.attributes['aria-expanded'], 'true');
});

test('keyboard navigation moves between panes and Escape returns focus to the trigger', () => {
    const { catalog, trigger, menu, roots, panels, document } = setup();
    catalog.emit('keydown', { target: trigger, key: 'ArrowDown' });
    assert.equal(document.activeElement, roots[0]);
    catalog.emit('keydown', { target: roots[0], key: 'ArrowDown' });
    assert.equal(document.activeElement, roots[1]);
    assert.equal(panels[1].hidden, false);
    catalog.emit('keydown', { target: roots[1], key: 'ArrowRight' });
    assert.equal(document.activeElement, panels[1].children[0]);
    catalog.emit('keydown', { target: panels[1].children[0], key: 'ArrowLeft' });
    assert.equal(document.activeElement, roots[1]);
    catalog.emit('keydown', { target: roots[1], key: 'Escape' });
    assert.equal(menu.hidden, true);
    assert.equal(document.activeElement, trigger);
});

test('touch hover does not open the catalog and a viewport change closes it', () => {
    const { catalog, trigger, menu, desktop } = setup();
    catalog.emit('pointerenter', { pointerType: 'touch' });
    assert.equal(menu.hidden, true);
    trigger.emit('click');
    desktop.matches = false;
    desktop.change();
    assert.equal(menu.hidden, true);
    assert.equal(trigger.attributes['aria-expanded'], 'false');
});
