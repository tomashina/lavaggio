import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../../public/front-theme/scripts/lavaggio-products.js', import.meta.url), 'utf8');

function legalPanel(initiallyOpen = false, animationsEnabled = true) {
    const listeners = {};
    const summaryListeners = {};
    const classes = new Set();
    const summaryHeight = 64;
    const contentHeight = 120;
    let runningAnimation;
    const panel = {
        open: initiallyOpen,
        dataset: {},
        style: { height: '' },
        animations: [],
        classList: {
            add(name) { classes.add(name); },
            remove(name) { classes.delete(name); },
            contains(name) { return classes.has(name); },
        },
        addEventListener(event, handler) { listeners[event] = handler; },
        toggle() { listeners.toggle?.(); },
        querySelector(selector) {
            if (selector === 'summary') return summary;
            if (selector === '.lavaggio-legal-content') return content;
            return null;
        },
        getBoundingClientRect() {
            return { height: runningAnimation?.height ?? (panel.open ? summaryHeight + contentHeight + 1 : summaryHeight + 1) };
        },
        get offsetHeight() { return panel.getBoundingClientRect().height; },
        get clientHeight() { return panel.offsetHeight - 1; },
    };
    const summary = {
        addEventListener(event, handler) { summaryListeners[event] = handler; },
        getBoundingClientRect: () => ({ height: summaryHeight }),
        offsetHeight: summaryHeight,
        click() {
            const event = {
                defaultPrevented: false,
                button: 0,
                target: summary,
                preventDefault() { this.defaultPrevented = true; },
            };
            summaryListeners.click?.(event);
            if (!event.defaultPrevented) {
                panel.open = !panel.open;
                panel.toggle();
            }
            return event;
        },
    };
    const content = {
        getBoundingClientRect: () => ({ height: contentHeight }),
        offsetHeight: contentHeight,
        scrollHeight: contentHeight,
    };
    panel.summary = summary;
    if (animationsEnabled) {
        panel.animate = (keyframes, options) => {
            const startHeight = parseFloat(keyframes[0].height);
            const endHeight = parseFloat(keyframes.at(-1).height);
            const animation = {
                keyframes, options,
                height: startHeight,
                canceled: false,
                onfinish: null,
                oncancel: null,
                progress(fraction) { this.height = startHeight + (endHeight - startHeight) * fraction; },
                cancel() {
                    this.canceled = true;
                    if (runningAnimation === this) runningAnimation = null;
                    this.oncancel?.();
                },
                finish() {
                    if (this.canceled) return;
                    this.height = endHeight;
                    this.onfinish?.();
                    if (runningAnimation === this) runningAnimation = null;
                },
            };
            panel.animations.push(animation);
            runningAnimation = animation;
            return animation;
        };
    }
    return panel;
}

function page(endsAt, now = Date.parse('2026-10-28T10:00:00Z'), { initiallyOpen = [], reducedMotion = false, animationsEnabled = true } = {}) {
    let currentTime = now;
    let tick;
    let reloads = 0;
    const parts = Object.fromEntries(['days', 'hours', 'minutes', 'seconds'].map((part) => [part, { textContent: '' }]));
    const countdown = {
        hidden: false, dataset: { endsAt },
        querySelector(selector) {
            const part = selector.match(/^\[data-countdown-part="(days|hours|minutes|seconds)"\]$/)?.[1];
            return parts[part] ?? null;
        },
        hasAttribute: (attribute) => attribute === 'data-refresh-on-expiry',
    };
    const listeners = {};
    const panels = [0, 1].map((index) => legalPanel(initiallyOpen.includes(index), animationsEnabled));
    const document = {
        querySelectorAll: (selector) => selector === '[data-lavaggio-countdown]' ? [countdown] : panels,
        addEventListener(event, callback) { listeners[event] = callback; },
    };
    vm.runInNewContext(script, {
        document, Date: { parse: Date.parse, now: () => currentTime },
        window: {
            location: { reload() { reloads++; } },
            setInterval(callback) { tick = callback; return 1; },
            clearInterval() { tick = null; },
            matchMedia: () => ({ matches: reducedMotion }),
        },
    });
    return { parts, countdown, panels, get reloads() { return reloads; }, advance(ms) { currentTime += ms; tick?.(); } };
}

test('countdown displays days, hours, minutes and seconds and advances every second', () => {
    const view = page('2026-10-30T13:04:05Z');
    assert.equal(view.parts.days.textContent, '02');
    assert.equal(view.parts.hours.textContent, '03');
    assert.equal(view.parts.minutes.textContent, '04');
    assert.equal(view.parts.seconds.textContent, '05');
    view.advance(1000);
    assert.equal(view.parts.days.textContent, '02');
    assert.equal(view.parts.hours.textContent, '03');
    assert.equal(view.parts.minutes.textContent, '04');
    assert.equal(view.parts.seconds.textContent, '04');
});

test('countdown updates each unit together across a day boundary', () => {
    const view = page('2026-10-29T10:00:00Z');
    assert.deepEqual(Object.values(view.parts).map((part) => part.textContent), ['01', '00', '00', '00']);
    view.advance(1000);
    assert.deepEqual(Object.values(view.parts).map((part) => part.textContent), ['00', '23', '59', '59']);
});

test('expired promotion hides the countdown and refreshes prices exactly once', () => {
    const view = page('2026-10-28T10:00:01Z');
    view.advance(1000);
    assert.equal(view.countdown.hidden, true);
    assert.equal(view.reloads, 1);
    view.advance(1000);
    assert.equal(view.reloads, 1);
});

test('invalid promotion timestamp never shows a misleading countdown', () => {
    const view = page('invalid');
    assert.equal(view.countdown.hidden, true);
    assert.equal(view.reloads, 0);
});

test('opening a legal information panel slides its sibling closed before hiding it', () => {
    const { panels } = page('2026-10-30T13:04:05Z', undefined, { initiallyOpen: [0] });
    const event = panels[1].summary.click();

    assert.equal(event.defaultPrevented, true);
    assert.equal(panels[0].open, true, 'closing content stays visible while its height shrinks');
    assert.equal(panels[1].open, true);
    assert.equal(panels[0].animations.length, 1);
    assert.equal(panels[1].animations.length, 1);
    assert.ok(parseFloat(panels[0].animations[0].keyframes[0].height) > parseFloat(panels[0].animations[0].keyframes[1].height));
    assert.ok(parseFloat(panels[1].animations[0].keyframes[0].height) < parseFloat(panels[1].animations[0].keyframes[1].height));
    panels[0].toggle();
    panels[1].toggle();

    panels[0].animations[0].finish();
    panels[1].animations[0].finish();
    assert.equal(panels[0].open, false);
    assert.equal(panels[1].open, true);
    assert.equal(panels[0].style.height, '');
    assert.equal(panels[1].style.height, '', 'open content can grow naturally after the animation');
});

test('native legal panel changes still close siblings', () => {
    const { panels } = page('2026-10-30T13:04:05Z', undefined, { initiallyOpen: [0] });
    panels[1].open = true;
    panels[1].toggle();
    assert.equal(panels[1].dataset.expanded, 'true');
    assert.equal(panels[0].animations.length, 1);
    panels[0].animations[0].finish();
    assert.equal(panels[0].open, false);
    assert.equal(panels[1].open, true);
});

test('rapid legal panel clicks reverse from the current height and honor the last click', () => {
    const { panels } = page('2026-10-30T13:04:05Z');
    const panel = panels[0];
    panel.summary.click();
    const opening = panel.animations[0];
    opening.progress(0.4);
    const intermediateHeight = panel.getBoundingClientRect().height;

    panel.summary.click();
    const closing = panel.animations[1];
    assert.equal(opening.canceled, true);
    assert.equal(parseFloat(closing.keyframes[0].height), intermediateHeight);
    assert.ok(parseFloat(closing.keyframes[1].height) < intermediateHeight);
    closing.progress(0.3);
    const reverseHeight = panel.getBoundingClientRect().height;

    panel.summary.click();
    const reopening = panel.animations[2];
    assert.equal(closing.canceled, true);
    assert.equal(parseFloat(reopening.keyframes[0].height), reverseHeight);
    reopening.finish();
    assert.equal(panel.open, true);
    assert.equal(panel.style.height, '');
});

test('reduced motion opens and closes legal panels immediately', () => {
    const { panels } = page('2026-10-30T13:04:05Z', undefined, { initiallyOpen: [0], reducedMotion: true });
    panels[1].summary.click();
    assert.equal(panels[0].open, false);
    assert.equal(panels[1].open, true);
    panels[1].summary.click();
    assert.equal(panels[1].open, false);
    assert.equal(panels[0].animations.length + panels[1].animations.length, 0);
});

test('legal panels retain single-panel behavior when browser animations are unavailable', () => {
    const { panels } = page('2026-10-30T13:04:05Z', undefined, { initiallyOpen: [0], animationsEnabled: false });
    panels[1].summary.click();
    assert.equal(panels[0].open, false);
    assert.equal(panels[1].open, true);
});
