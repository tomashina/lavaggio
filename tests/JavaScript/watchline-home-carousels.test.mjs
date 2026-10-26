import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/front-theme/scripts/watchline-home-carousels.js', import.meta.url), 'utf8');

class Target {
    listeners = new Map();
    addEventListener(type, listener, options) {
        const rows = this.listeners.get(type) ?? [];
        rows.push({ listener, capture: options === true || options?.capture === true });
        this.listeners.set(type, rows);
    }
    emit(type, event = {}) {
        for (const { listener } of this.listeners.get(type) ?? []) listener(event);
    }
}

function row({ continuous = true, scrollLeft = 0 } = {}) {
    const element = new Target();
    Object.assign(element, {
        dataset: {}, isConnected: true, continuous,
        track: { scrollLeft },
        matches: (selector) => selector === '[data-continuous-card-carousel]' && continuous,
        contains: (target) => target.row === element,
        querySelector: (selector) => selector === '.splide__track' ? element.track : null,
        querySelectorAll: (selector) => selector === '.splide__slide' ? element.slides : element.controls,
    });
    element.slides = Array.from({ length: 8 }, (_, index) => ({ offsetLeft: index * 200 }));
    const control = (kind, index = 0) => {
        const node = new Target();
        Object.assign(node, {
            row: element, kind, tabindex: null,
            classList: { contains: (name) => kind === 'prev' && name === 'splide__arrow--prev' },
            getAttribute: () => node.tabindex,
            setAttribute: (name, value) => { node.tabindex = value; },
            removeAttribute: () => { node.tabindex = null; },
            closest: (selector) => selector === '.splide__slide' && kind === 'link' ? element.slides[index]
                : selector === '.splide__arrow' && ['prev', 'next'].includes(kind) ? node : null,
        });
        return node;
    };
    element.links = element.slides.map((_, index) => control('link', index));
    element.prev = control('prev');
    element.next = control('next');
    element.controls = [...element.links, element.prev, element.next];
    element.dispatch = (type, target = element.links[0]) => {
        const event = { target, defaultPrevented: false, stopped: false,
            preventDefault() { this.defaultPrevented = true; }, stopPropagation() { this.stopped = true; } };
        for (const { listener, capture } of element.listeners.get(type) ?? []) {
            if (capture) listener(event);
        }
        if (!event.stopped) target.emit(type, event);
        return event;
    };
    return element;
}

function page({ rows = [row(), row()], intersection = true, library = true, watchline = true, domReady = true } = {}) {
    const window = new Target();
    const document = new Target();
    const loader = new Target();
    const timers = new Map();
    const mounts = [];
    const observers = [];
    let timerId = 0;
    Object.assign(document, {
        readyState: domReady ? 'interactive' : 'loading',
        body: { classList: { contains: (name) => name === 'watchline-storefront' && watchline } },
        querySelector: (selector) => selector === '[data-storefront-splide]' ? loader : null,
        querySelectorAll: () => rows,
    });
    Object.assign(window, {
        setInterval: (callback) => { timers.set(++timerId, callback); return timerId; },
        clearInterval: (id) => timers.delete(id),
    });
    const installLibrary = () => {
        window.Splide = class {
            constructor(element, options) {
                if (element.invalid) throw new Error('Invalid test options');
                Object.assign(this, { element, options, moves: [], refreshes: 0 });
            }
            mount(extensions) {
                this.extensions = extensions;
                mounts.push(this);
                this.element.prev.addEventListener('click', () => this.go('<'));
                this.element.next.addEventListener('click', () => this.go('>'));
            }
            go(direction) { this.moves.push(direction); }
            refresh() { this.refreshes++; }
            destroy() {}
        };
    };
    if (library) installLibrary();
    if (intersection) {
        window.IntersectionObserver = class {
            constructor(callback, options) {
                Object.assign(this, { callback, options, observed: new Set() });
                observers.push(this);
            }
            observe(element) { this.observed.add(element); }
            unobserve(element) { this.observed.delete(element); }
            disconnect() { this.observed.clear(); }
        };
    }
    const context = vm.createContext({ window, document });
    const run = () => vm.runInContext(source, context);
    run();
    return {
        rows, mounts, observers, timers, window, document, run,
        near: (element, isIntersecting = true) => observers[0].callback([{ target: element, isIntersecting }]),
        loadLibrary() { installLibrary(); loader.emit('load'); },
        tick() { [...timers.values()].forEach((callback) => callback()); },
        domReady() { document.readyState = 'interactive'; document.emit('DOMContentLoaded'); },
    };
}

test('only near-viewport rows mount, once, with the existing motion and native tap behavior', () => {
    const p = page();
    assert.equal(p.mounts.length, 0, 'Offscreen rows must not create clones during initial load.');
    assert.equal(p.observers[0].options.rootMargin, '500px 0px');
    p.near(p.rows[0], false);
    assert.equal(p.mounts.length, 0);
    p.near(p.rows[0]);
    p.near(p.rows[0]);
    assert.equal(p.mounts.length, 1);
    assert.equal(p.mounts[0].options.speed, 420);
    assert.equal(p.mounts[0].options.easing, 'cubic-bezier(0.25, 1, 0.5, 1)');
    assert.equal(p.mounts[0].options.noDrag, undefined, 'Keep the existing vendor tap/swipe fix.');
    assert.equal(p.rows[1].dataset.splideReady, undefined);
    assert.equal(p.rows[0].dispatch('click').defaultPrevented, false);
});

test('an approaching row waits for a delayed library without mounting unrelated rows', () => {
    const p = page({ library: false });
    p.near(p.rows[0]);
    p.tick();
    assert.equal(p.mounts.length, 0);
    p.loadLibrary();
    p.near(p.rows[0]);
    assert.equal(p.mounts.length, 1);
    assert.equal(p.timers.size, 0);
    assert.equal(p.rows[1].dataset.splideReady, undefined);
});

test('a script load event still activates the requested row after bounded polling expires', () => {
    const p = page({ library: false });
    p.near(p.rows[0]);
    for (let count = 0; count < 40; count++) p.tick();
    assert.equal(p.timers.size, 0);
    assert.equal(p.rows[0].dataset.splideReady, undefined);
    p.loadLibrary();
    assert.equal(p.mounts.length, 1);
});

test('without IntersectionObserver all rows retain eager behavior and duplicate scripts do not remount', () => {
    const p = page({ intersection: false });
    assert.equal(p.mounts.length, 2);
    p.run();
    assert.equal(p.mounts.length, 2);
});

test('other storefronts do not get observers, polling, or changed carousel behavior', () => {
    const p = page({ watchline: false, library: false });
    assert.equal(p.mounts.length, 0);
    assert.equal(p.observers.length, 0);
    assert.equal(p.timers.size, 0);
    assert.equal(p.window.listeners.size, 0);
});

test('a first arrow click without pointerdown mounts and advances exactly once', () => {
    const p = page();
    const first = p.rows[0].dispatch('click', p.rows[0].next);
    assert.equal(first.defaultPrevented, true);
    assert.equal(p.mounts.length, 1);
    assert.equal(JSON.stringify(p.mounts[0].moves), '[">"]');
    p.rows[0].dispatch('click', p.rows[0].next);
    assert.equal(JSON.stringify(p.mounts[0].moves), '[">",">"]');
});

test('pointer preparation preserves the original arrow click and product navigation', () => {
    const p = page();
    p.rows[0].dispatch('pointerdown', p.rows[0].next);
    assert.equal(p.rows[0].dispatch('click', p.rows[0].next).defaultPrevented, false);
    assert.equal(JSON.stringify(p.mounts[0].moves), '[">"]');
    const next = p.rows[1];
    assert.equal(next.dispatch('touchstart', next.links[1]).defaultPrevented, false);
    assert.equal(next.dispatch('click', next.links[1]).defaultPrevented, false);
    assert.equal(p.mounts.length, 2);
});

test('a first arrow action is replayed once after the library arrives, while links stay native', () => {
    const p = page({ library: false });
    assert.equal(p.rows[0].dispatch('click', p.rows[0].prev).defaultPrevented, true);
    assert.equal(p.rows[1].dispatch('click').defaultPrevented, false);
    p.loadLibrary();
    assert.equal(p.mounts.length, 2);
    assert.equal(JSON.stringify(p.mounts[0].moves), '["<"]');
    p.loadLibrary();
    assert.equal(JSON.stringify(p.mounts[0].moves), '["<"]');
});

test('keyboard focus keeps a later fallback card visible and static-grid teardown restores original tab stops', () => {
    const p = page();
    const el = p.rows[0];
    el.links[0].tabindex = '0';
    el.dispatch('focusin', el.links[6]);
    assert.equal(p.mounts[0].options.start, 6);
    el.controls.forEach((node) => { node.tabindex = '-1'; });
    p.mounts[0].extensions.RestoreCardGridFocus().destroy();
    assert.equal(el.links[0].tabindex, '0');
    assert.equal(el.links[6].tabindex, null);
});

test('mounting after native fallback scrolling keeps the same item without double scroll offsets', () => {
    const el = row({ scrollLeft: 410 });
    const p = page({ rows: [el] });
    p.near(el);
    assert.equal(p.mounts[0].options.start, 2);
    assert.equal(el.track.scrollLeft, 0);
});

test('orientation and BFCache restoration preserve mounted instances and reconsider untouched rows', () => {
    const p = page();
    p.near(p.rows[0]);
    p.window.emit('orientationchange');
    assert.equal(p.mounts.length, 1);
    p.window.emit('pagehide');
    p.near(p.rows[1]);
    assert.equal(p.mounts.length, 1);
    p.window.emit('pageshow', { persisted: true });
    assert.equal(p.mounts.length, 1);
    assert.equal(p.mounts[0].refreshes, 1);
    assert.equal(p.observers[0].observed.has(p.rows[1]), true);
    p.near(p.rows[1]);
    assert.equal(p.mounts.length, 2);
});

test('a library loading while the page is frozen waits until BFCache restoration', () => {
    const p = page({ library: false });
    p.near(p.rows[0]);
    p.window.emit('pagehide');
    p.loadLibrary();
    assert.equal(p.mounts.length, 0);
    p.window.emit('pageshow', { persisted: true });
    assert.equal(p.mounts.length, 1);
});

test('DOM loading and invalid individual widget options do not break the remaining card rows', () => {
    const p = page({ domReady: false });
    assert.equal(p.observers.length, 0);
    p.domReady();
    p.rows[0].invalid = true;
    p.near(p.rows[0]);
    assert.equal(p.rows[0].dataset.splideReady, undefined);
    p.near(p.rows[1]);
    assert.equal(p.mounts.length, 1);
});
