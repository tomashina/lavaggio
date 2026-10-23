import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../../public/front-theme/scripts/libermedia-richtext-tables.js', import.meta.url), 'utf8');

function page({ brand = true, existing = false, excluded = false, original = {} } = {}) {
    class Element {
        constructor(tag, classes = []) {
            this.tagName = tag.toUpperCase();
            this.nodeType = 1;
            this.attributes = new Map();
            this.children = [];
            this.parentElement = null;
            this.width = 320;
            this.naturalWidth = 0;
            this.classes = new Set(classes);
            this.classList = { add: (name) => this.classes.add(name), contains: (name) => this.classes.has(name) };
        }
        get clientWidth() { return this.width; }
        get scrollWidth() { return Math.max(this.width, this.naturalWidth, ...this.children.map((node) => node.scrollWidth)); }
        get isConnected() { return this.root || !!this.parentElement?.isConnected; }
        setAttribute(key, value) { this.attributes.set(key, value); }
        getAttribute(key) { return this.attributes.get(key) ?? null; }
        hasAttribute(key) { return this.attributes.has(key); }
        removeAttribute(key) { this.attributes.delete(key); }
        matches(selector) {
            return selector.split(',').some((item) => {
                const rule = item.trim();
                if (rule.startsWith('.')) return this.classes.has(rule.slice(1));
                if (rule.startsWith('[')) return this.hasAttribute(rule.slice(1, -1));
                return this.tagName === rule.toUpperCase();
            });
        }
        querySelectorAll(selector) {
            return this.children.flatMap((child) => [...(child.matches(selector) ? [child] : []), ...child.querySelectorAll(selector)]);
        }
        closest(selector) {
            for (let node = this; node; node = node.parentElement) if (node.matches(selector)) return node;
            return null;
        }
        append(node) {
            if (node.parentElement) node.parentElement.children.splice(node.parentElement.children.indexOf(node), 1);
            node.parentElement = this;
            this.children.push(node);
        }
        before(node) {
            this.parentElement.children.splice(this.parentElement.children.indexOf(this), 0, node);
            node.parentElement = this.parentElement;
        }
    }
    const content = new Element('div', ['content-richtext']);
    content.root = true;
    if (excluded) content.setAttribute('data-richtext-table-exclude', '');
    const table = new Element('table', ['table', 'table-striped']);
    table.naturalWidth = 900;
    table.caption = { textContent: '  Usporedba   proizvoda  ' };
    table.setAttribute('data-source', 'untouched');
    const providedWrapper = existing ? new Element('div', ['table-responsive']) : null;
    if (providedWrapper) {
        for (const [key, value] of Object.entries(original)) providedWrapper.setAttribute(key, value);
        content.append(providedWrapper);
        providedWrapper.append(table);
    } else content.append(table);
    const operations = new Element('div');
    operations.root = true;
    const cartTable = new Element('table');
    operations.append(cartTable);
    const documentListeners = new Map();
    const windowListeners = new Map();
    const observers = [];
    const mutationObservers = [];
    let created = 0;
    const document = {
        body: { classList: { contains: () => brand } }, readyState: 'complete',
        querySelectorAll() { return [table, cartTable].filter((node) => node.closest('.content-richtext, .product-detail-description-content, .catalog-description, .prose, [data-richtext-content]')); },
        createElement(tag) { created++; return new Element(tag); },
        addEventListener(type, callback) { documentListeners.set(type, callback); },
    };
    const window = {
        getComputedStyle: () => ({ overflowX: 'visible' }),
        addEventListener(type, callback) { windowListeners.set(type, callback); },
    };
    class ResizeObserver {
        constructor(callback) { this.callback = callback; observers.push(this); }
        observe() {}
        unobserve() {}
    }
    class MutationObserver {
        constructor(callback) { this.callback = callback; mutationObservers.push(this); }
        observe() {}
    }
    vm.runInNewContext(script, { document, window, ResizeObserver, MutationObserver });
    return { table, content, cartTable, operations, providedWrapper, created: () => created,
        navigate: () => documentListeners.get('livewire:navigated')(), resize: () => observers[0].callback(),
        appendTable(parent = content) {
            const table = new Element('table');
            table.naturalWidth = 900;
            parent.append(table);
            mutationObservers[0].callback([{ addedNodes: [table] }]);
            return table;
        } };
}

test('wide editorial tables keep their original nodes, caption and attributes inside an accessible scroll region', () => {
    const p = page();
    const wrapper = p.table.parentElement;
    assert.equal(wrapper.classList.contains('lm-richtext-table-scroll'), true);
    assert.equal(wrapper.children[0], p.table);
    assert.equal(p.table.getAttribute('data-source'), 'untouched');
    assert.equal(p.table.classList.contains('table-striped'), true);
    assert.equal(p.table.caption.textContent, '  Usporedba   proizvoda  ');
    assert.equal(p.table.hasAttribute('role'), false);
    assert.equal(wrapper.getAttribute('role'), 'region');
    assert.equal(wrapper.getAttribute('tabindex'), '0');
    assert.equal(wrapper.getAttribute('aria-label'), 'Tablica: Usporedba proizvoda');
    assert.equal(p.cartTable.parentElement, p.operations);
    wrapper.width = 1000;
    p.resize();
    assert.equal(wrapper.hasAttribute('tabindex'), false);
    assert.equal(wrapper.hasAttribute('role'), false);
    wrapper.width = 320;
    p.resize();
    assert.equal(wrapper.getAttribute('tabindex'), '0');
});

test('existing table wrappers and their accessibility labels are reused without nesting or overwriting', () => {
    const p = page({ existing: true, original: { role: 'group', 'aria-label': 'Izvorna tablica', tabindex: '0' } });
    assert.equal(p.table.parentElement, p.providedWrapper);
    assert.equal(p.created(), 0);
    assert.equal(p.providedWrapper.getAttribute('aria-label'), 'Izvorna tablica');
    p.navigate();
    assert.equal(p.created(), 0);
    p.providedWrapper.width = 1000;
    p.resize();
    assert.equal(p.providedWrapper.getAttribute('role'), 'group');
    assert.equal(p.providedWrapper.getAttribute('tabindex'), '0');
});

test('other storefronts and explicitly excluded tables are left alone', () => {
    const otherBrand = page({ brand: false });
    assert.equal(otherBrand.table.parentElement, otherBrand.content);
    assert.equal(otherBrand.created(), 0);
    const excluded = page({ excluded: true });
    assert.equal(excluded.table.parentElement, excluded.content);
    assert.equal(excluded.created(), 0);
});

test('tables loaded into content later get the same treatment while dynamic operational tables remain untouched', () => {
    const p = page();
    const table = p.appendTable();
    assert.equal(table.parentElement.classList.contains('lm-richtext-table-scroll'), true);
    assert.equal(table.parentElement.getAttribute('tabindex'), '0');
    const operationsTable = p.appendTable(p.operations);
    assert.equal(operationsTable.parentElement, p.operations);
});
