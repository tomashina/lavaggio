import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const vendorSource = readFileSync(new URL('../../public/vendor/splide/splide.min.js', import.meta.url), 'utf8');
const storefrontSource = readFileSync(new URL('../../resources/views/front/partials/splide-assets.blade.php', import.meta.url), 'utf8');
const thresholds = storefrontSource.match(/dragMinThreshold:\s*\{\s*mouse:\s*(\d+),\s*touch:\s*(\d+)\s*\}/);
assert.ok(thresholds, 'Shared storefront drag thresholds must be configured.');
const dragMinThreshold = { mouse: Number(thresholds[1]), touch: Number(thresholds[2]) };
const noDrag = storefrontSource.match(/noDrag:\s*'([^']+)'/)?.[1];
assert.ok(noDrag, 'Shared storefront controls must opt out of dragging.');
const initializers = [
    ['home', 'resources/views/front/partials/herrera-home-carousel-script.blade.php', '[data-herrera-home-carousel]'],
    ['CMS products', 'resources/views/front/content-blocks/types/products_carousel.blade.php', '[data-products-carousel-splide]'],
    ['legacy CMS products', 'resources/views/front/content-blocks/instances/izdvojeniartikli.blade.php', '[data-products-carousel-splide]'],
    ['related products', 'public/front-theme/scripts/product-page.js', '[data-related-products-splide]'],
].map(([name, path, selector]) => {
    const source = readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
    const script = path.endsWith('.php') ? [...source.matchAll(/<script>([\s\S]*?)<\/script>/g)].at(-1)[1] : source;
    return { name, selector, script: script.replace('{{ $mobileDefaultCols }}', '2').replace('{{ $preferredGridCols }}', '6') };
});

function homeOptions({ watchline = true, continuous = true, initializer = initializers[0], dataset = {} } = {}) {
    let options;
    const element = {
        dataset: { ...dataset },
        querySelectorAll(selector) { return selector === '.splide__slide' ? [{}, {}, {}, {}, {}, {}, {}, {}] : []; },
        matches(selector) { return selector === '[data-continuous-card-carousel]' && continuous; },
    };
    const document = {
        body: { classList: { contains(name) { return name === 'watchline-storefront' && watchline; } } },
        readyState: 'complete',
        querySelector() { return null; },
        querySelectorAll(selector) { return selector === initializer.selector ? [element] : []; },
    };
    const window = { Splide: class {
        constructor(node, value) { options = value; }
        mount() {}
    } };
    vm.runInNewContext(initializer.script, { document, window });
    return options ?? {};
}

class Target {
    listeners = new Map();

    addEventListener(type, callback) {
        const callbacks = this.listeners.get(type) ?? [];
        callbacks.push(callback);
        this.listeners.set(type, callbacks);
    }

    removeEventListener(type, callback) {
        this.listeners.set(type, (this.listeners.get(type) ?? []).filter((handler) => handler !== callback));
    }

    dispatchEvent(event) {
        event.target ??= this;
        for (const callback of [...(this.listeners.get(event.type) ?? [])]) {
            callback(event);
            if (event.immediateStopped) break;
        }
        return !event.defaultPrevented;
    }
}

class Element extends Target {
    constructor(tagName = 'div', parentElement = null) {
        super();
        this.tagName = tagName;
        this.parentElement = parentElement;
        this.ownerDocument = { defaultView: { HTMLElement: Element } };
    }

    matches(selector) {
        return selector.split(',').some((part) => {
            part = part.trim();
            if (part.endsWith(' *')) {
                let parent = this.parentElement;
                while (parent) {
                    if (parent.tagName === part.slice(0, -2)) return true;
                    parent = parent.parentElement;
                }
                return false;
            }
            return part === this.tagName;
        });
    }
}

class GestureEvent {
    constructor(type, target, x = 0, y = 0, timeStamp = 0) {
        Object.assign(this, { type, target, pageX: x, pageY: y, timeStamp, cancelable: true, button: 0 });
        this.defaultPrevented = false;
        this.immediateStopped = false;
    }

    preventDefault() { this.defaultPrevented = true; }
    stopPropagation() {}
    stopImmediatePropagation() { this.immediateStopped = true; }
}

class TouchEvent extends GestureEvent {
    constructor(...args) {
        super(...args);
        this.changedTouches = [{ pageX: this.pageX, pageY: this.pageY }];
    }
}

function carousel({ configuredNoDrag = noDrag, initialState = 3, waitForTransition = true } = {}) {
    const window = new Target();
    window.HTMLElement = Element;
    const context = vm.createContext({ window, TouchEvent, CustomEvent: GestureEvent, setTimeout });
    const exportHook = 'return t.defaults={},t.STATES=r,t}';
    assert.equal(vendorSource.split(exportHook).length, 2, 'Vendor component export hook must match exactly once.');
    // Expose the real component only inside this test VM; never alter the shipped API.
    vm.runInContext(vendorSource.replace(exportHook, `globalThis.testDrag=Ut.Drag;${exportHook}`), context);

    const track = new Element();
    const link = new Element('a');
    link.href = '/product/example-watch';
    const image = new Element('img', link);
    const title = new Element('span', link);
    const cloneLink = new Element('a');
    cloneLink.href = link.href;
    const cloneImage = new Element('img', cloneLink);
    const content = new Element('p');
    const button = new Element('button');
    const bus = new Target();
    const moves = [];
    const destinations = [];
    const cancellations = [];
    let state = initialState;
    let position = 0;
    const splide = {
        event: { bus, on() {} },
        state: {
            is(value) { return Array.isArray(value) ? value.includes(state) : state === value; },
            set(value) { state = value; },
        },
        is(type) { return type === 'loop'; },
    };
    const components = {
        Elements: { track },
        Move: {
            getPosition() { return position; },
            exceededLimit() { return false; },
            cancel() { cancellations.push('move'); },
            translate(value) { position = value; moves.push(value); },
        },
        Scroll: { cancel() { cancellations.push('scroll'); } },
        Controller: {
            isBusy() { return [4, 5].includes(state) && waitForTransition; },
            go(value) { destinations.push(value); },
            toDest(value) { return value; },
        },
        Layout: { listSize() { return 1000; } },
        Media: { reduce() {} },
        Direction: { resolve(value) { return value; }, orient(value) { return value; } },
    };
    const drag = context.testDrag(splide, components, { drag: true, dragMinThreshold, noDrag: configuredNoDrag, flickPower: 600 });
    drag.mount();
    bus.dispatchEvent({ type: 'mounted', detail: [] });

    function dispatch(type, x, y, time, { touch = true, target = link } = {}) {
        const event = new (touch ? TouchEvent : GestureEvent)(type, target, x, y, time);
        (touch || type === 'mousedown' || type === 'click' ? track : window).dispatchEvent(event);
        return event;
    }

    return { dispatch, moves, destinations, cancellations, button, link, image, title, cloneImage, cloneLink, content, drag, state: () => state };
}

for (const initializer of initializers) {
    test(`${initializer.name}: Watchline card rows use the same smooth motion and keep image swiping enabled`, () => {
        const options = homeOptions({ initializer });
        assert.equal(options.speed, 420);
        assert.equal(options.easing, 'cubic-bezier(0.25, 1, 0.5, 1)');
        assert.equal(options.noDrag, undefined, 'Image links must support taps and deliberate swipes.');
        for (const guards of [{ watchline: false }, { continuous: false }, { watchline: false, continuous: false }]) {
            const options = homeOptions({ initializer, ...guards });
            assert.equal(options.speed, initializer.name === 'home' ? undefined : 520);
            assert.equal(options.easing, undefined);
        }
    });
}

for (const initializer of initializers.filter(({ name }) => name.includes('CMS'))) {
    test(`${initializer.name}: each CMS row reads its own column settings`, () => {
        const first = homeOptions({ initializer, dataset: { desktopCols: '3', mobileCols: '1' } });
        const second = homeOptions({ initializer, dataset: { desktopCols: '6', mobileCols: '2' } });
        assert.equal(first.perPage, 3);
        assert.equal(first.breakpoints[640].perPage, 1);
        assert.equal(second.perPage, 6);
        assert.equal(second.breakpoints[640].perPage, 2);
    });
}

for (const initialState of [3, 4, 5]) {
    for (const targetName of ['link', 'image', 'title', 'cloneImage']) {
        for (const jitter of [0, 18, 24]) {
            test(`${targetName} keeps its native tap with ${jitter}px movement in slider state ${initialState}`, () => {
                const slider = carousel({ initialState });
                const target = { target: slider[targetName] };
                assert.equal(slider.dispatch('touchstart', 100, 100, 0, target).defaultPrevented, false);
                if (jitter) assert.equal(slider.dispatch('touchmove', 100 - jitter, 100, 30, target).defaultPrevented, false);
                assert.equal(slider.dispatch('touchend', 100 - jitter, 100, 60, target).defaultPrevented, false);
                assert.equal(slider.dispatch('click', 100 - jitter, 100, 70, { ...target, touch: false }).defaultPrevented, false);
                assert.deepEqual(slider.moves, []);
                assert.deepEqual(slider.destinations, []);
                assert.deepEqual(slider.cancellations, [], 'Taps must not cancel a running transition.');
                assert.equal(slider.state(), initialState);
                assert.equal(slider.link.href, '/product/example-watch');
            });
        }
    }

    for (const targetName of ['image', 'cloneImage', 'content']) {
        test(`${targetName} supports a deliberate swipe in slider state ${initialState}`, () => {
            const slider = carousel({ initialState });
            const target = { target: slider[targetName] };
            slider.dispatch('touchstart', 100, 100, 0, target);
            assert.deepEqual(slider.cancellations, []);
            assert.equal(slider.dispatch('touchmove', 82, 100, 20, target).defaultPrevented, false);
            assert.deepEqual(slider.cancellations, []);
            assert.equal(slider.dispatch('touchmove', 70, 100, 40, target).defaultPrevented, true);
            assert.deepEqual(slider.cancellations, ['move', 'scroll']);
            assert.equal(slider.state(), 6);
            assert.equal(slider.dispatch('touchmove', 40, 100, 60, target).defaultPrevented, true);
            assert.equal(slider.dispatch('touchend', 40, 100, 90, target).defaultPrevented, true);
            assert.ok(slider.moves.length > 0);
            assert.equal(slider.destinations.length, 1);
            assert.equal(slider.dispatch('click', 40, 100, 100, { ...target, touch: false }).defaultPrevented, true);
        });
    }

    test(`vertical scrolling leaves slider state ${initialState} and any running transition untouched`, () => {
        const slider = carousel({ initialState });
        slider.dispatch('touchstart', 100, 100, 0);
        assert.equal(slider.dispatch('touchmove', 104, 140, 30).defaultPrevented, false);
        assert.equal(slider.dispatch('touchmove', 106, 180, 60).defaultPrevented, false);
        assert.equal(slider.dispatch('touchend', 106, 180, 90).defaultPrevented, false);
        assert.deepEqual(slider.moves, []);
        assert.deepEqual(slider.destinations, []);
        assert.deepEqual(slider.cancellations, []);
        assert.equal(slider.state(), initialState);
    });
}

test('a swipe with only one movement event still suppresses the product click', () => {
    const slider = carousel({ initialState: 4 });
    slider.dispatch('touchstart', 100, 100, 0);
    assert.equal(slider.dispatch('touchmove', 60, 100, 30).defaultPrevented, true);
    assert.equal(slider.dispatch('touchend', 60, 100, 60).defaultPrevented, true);
    assert.equal(slider.dispatch('click', 60, 100, 70, { touch: false }).defaultPrevented, true);
    assert.equal(slider.destinations.length, 1);
});

test('small horizontal finger movement preserves a product tap without moving the slider', () => {
    assert.equal(dragMinThreshold.touch, 24);
    const slider = carousel();
    slider.dispatch('touchstart', 100, 100, 0);
    assert.equal(slider.dispatch('touchmove', 94, 101, 30).defaultPrevented, false);
    assert.equal(slider.dispatch('touchmove', 82, 102, 60).defaultPrevented, false);
    slider.dispatch('touchend', 82, 102, 90);
    assert.equal(slider.dispatch('click', 82, 102, 100, { touch: false }).defaultPrevented, false);
    assert.deepEqual(slider.moves, []);
    assert.deepEqual(slider.destinations, []);
});

test('touch movement at the threshold remains a tap, and a deliberate swipe cancels the click', () => {
    const slider = carousel();
    slider.dispatch('touchstart', 100, 100, 0);
    assert.equal(slider.dispatch('touchmove', 76, 100, 20).defaultPrevented, false);
    assert.equal(slider.dispatch('touchmove', 70, 100, 40).defaultPrevented, true);
    assert.equal(slider.dispatch('touchmove', 50, 100, 60).defaultPrevented, true);
    slider.dispatch('touchend', 50, 100, 80);
    assert.ok(slider.moves.length > 0);
    assert.equal(slider.destinations.length, 1);
    assert.equal(slider.dispatch('click', 50, 100, 90, { touch: false }).defaultPrevented, true);

    slider.dispatch('touchstart', 50, 100, 100);
    slider.dispatch('touchend', 50, 100, 140);
    assert.equal(slider.dispatch('click', 50, 100, 150, { touch: false }).defaultPrevented, false);
    assert.equal(slider.destinations.length, 1);
});

test('vertical scrolling is never canceled or converted into a slider movement', () => {
    const slider = carousel();
    slider.dispatch('touchstart', 100, 100, 0);
    assert.equal(slider.dispatch('touchmove', 104, 140, 30).defaultPrevented, false);
    assert.equal(slider.dispatch('touchmove', 106, 180, 60).defaultPrevented, false);
    slider.dispatch('touchend', 106, 180, 90);
    assert.deepEqual(slider.moves, []);
    assert.deepEqual(slider.destinations, []);
});

test('mouse movement retains the existing cancellation and drag behavior', () => {
    assert.equal(dragMinThreshold.mouse, 3);
    const slider = carousel();
    const mouse = { touch: false };
    slider.dispatch('mousedown', 100, 100, 0, mouse);
    assert.equal(slider.dispatch('mousemove', 98, 100, 20, mouse).defaultPrevented, true);
    assert.equal(slider.dispatch('mousemove', 94, 100, 40, mouse).defaultPrevented, true);
    assert.equal(slider.dispatch('mousemove', 90, 100, 60, mouse).defaultPrevented, true);
    slider.dispatch('mouseup', 90, 100, 80, mouse);
    assert.ok(slider.moves.length > 0);
    assert.equal(slider.destinations.length, 1);
    assert.equal(slider.dispatch('click', 90, 100, 90, mouse).defaultPrevented, true);
});

test('a direct button target does not start dragging or lose its click', () => {
    const slider = carousel();
    const control = { target: slider.button };
    slider.dispatch('touchstart', 100, 100, 0, control);
    assert.equal(slider.dispatch('touchmove', 50, 100, 40, control).defaultPrevented, false);
    slider.dispatch('touchend', 50, 100, 80, control);
    assert.equal(slider.dispatch('click', 50, 100, 90, { ...control, touch: false }).defaultPrevented, false);
    assert.deepEqual(slider.moves, []);
    assert.deepEqual(slider.destinations, []);
});
