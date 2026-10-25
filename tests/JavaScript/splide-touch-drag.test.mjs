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
    constructor(tagName = 'div') {
        super();
        this.tagName = tagName;
        this.ownerDocument = { defaultView: { HTMLElement: Element } };
    }

    matches(selector) {
        return selector.split(',').some((part) => part.trim() === this.tagName);
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

function carousel() {
    const window = new Target();
    window.HTMLElement = Element;
    const context = vm.createContext({ window, TouchEvent, CustomEvent: GestureEvent, setTimeout });
    const exportHook = 'return t.defaults={},t.STATES=r,t}';
    assert.equal(vendorSource.split(exportHook).length, 2, 'Vendor component export hook must match exactly once.');
    // Expose the real component only inside this test VM; never alter the shipped API.
    vm.runInContext(vendorSource.replace(exportHook, `globalThis.testDrag=Ut.Drag;${exportHook}`), context);

    const track = new Element();
    const link = new Element('a');
    const button = new Element('button');
    const bus = new Target();
    const moves = [];
    const destinations = [];
    let state = 3;
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
            cancel() {},
            translate(value) { position = value; moves.push(value); },
        },
        Scroll: { cancel() {} },
        Controller: {
            isBusy() { return false; },
            go(value) { destinations.push(value); },
            toDest(value) { return value; },
        },
        Layout: { listSize() { return 1000; } },
        Media: { reduce() {} },
        Direction: { resolve(value) { return value; }, orient(value) { return value; } },
    };
    const drag = context.testDrag(splide, components, { drag: true, dragMinThreshold, noDrag, flickPower: 600 });
    drag.mount();
    bus.dispatchEvent({ type: 'mounted', detail: [] });

    function dispatch(type, x, y, time, { touch = true, target = link } = {}) {
        const event = new (touch ? TouchEvent : GestureEvent)(type, target, x, y, time);
        (touch || type === 'mousedown' || type === 'click' ? track : window).dispatchEvent(event);
        return event;
    }

    return { dispatch, moves, destinations, button, drag };
}

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
