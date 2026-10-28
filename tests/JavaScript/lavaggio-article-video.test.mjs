import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../../public/front-theme/scripts/lavaggio-article-video.js', import.meta.url), 'utf8');

function harness({ supported = true, videoId = 'yOjuuBBVdmc' } = {}) {
    let document;
    const element = () => ({
        listeners: new Map(), dataset: {},
        addEventListener(type, callback) { this.listeners.set(type, [...(this.listeners.get(type) || []), callback]); },
        emit(type, event = {}) { for (const callback of this.listeners.get(type) || []) callback(event); },
        getAttribute() { return 'Pogledaj video: Što ozon stvarno radi'; },
        focus() { document.activeElement = this; },
    });
    const root = element();
    root.dataset.videoId = videoId;
    const trigger = element();
    const dialog = element();
    const player = element();
    const close = element();
    player.children = [];
    player.replaceChildren = (...children) => { player.children = children; };
    dialog.open = false;
    if (supported) dialog.showModal = () => { dialog.open = true; };
    dialog.close = () => { dialog.open = false; dialog.emit('close'); };
    const selectors = new Map([
        ['[data-lavaggio-video-open]', trigger], ['[data-lavaggio-video-dialog]', dialog],
        ['[data-lavaggio-video-player]', player], ['[data-lavaggio-video-close]', close],
    ]);
    root.querySelector = (selector) => selectors.get(selector) || null;
    const created = [];
    const body = { style: { overflow: 'auto', position: '', top: '', left: '', width: '', paddingRight: '5px' } };
    document = {
        body, activeElement: trigger, documentElement: { clientWidth: 1000, style: { scrollBehavior: 'smooth' } },
        querySelectorAll: () => [root], createElement: (tag) => { const node = { tag }; created.push(node); return node; },
    };
    const scrolls = [];
    const window = { scrollX: 0, scrollY: 520, innerWidth: 1024, getComputedStyle: () => ({ paddingRight: '10px' }), scrollTo: (...args) => scrolls.push(args) };
    const context = vm.createContext({ document, window });
    vm.runInContext(script, context);
    return {
        root, trigger, dialog, player, close, body, document, created, scrolls,
        initialize() { vm.runInContext(script, context); },
        click(extras = {}) {
            let prevented = false;
            trigger.emit('click', { button: 0, preventDefault() { prevented = true; }, ...extras });
            return prevented;
        },
    };
}

test('the player is created only after a click and uses the selected privacy embed with autoplay', () => {
    const page = harness();
    assert.equal(page.created.length, 0);
    assert.equal(page.click(), true);
    assert.equal(page.dialog.open, true);
    assert.equal(page.player.children.length, 1);
    const frame = page.player.children[0];
    assert.equal(frame.tag, 'iframe');
    assert.equal(frame.src, 'https://www.youtube-nocookie.com/embed/yOjuuBBVdmc?autoplay=1&playsinline=1&rel=0');
    assert.equal(frame.allowFullscreen, true);
    assert.equal(page.document.activeElement, page.close);
});

test('closing stops playback and restores scrolling, original styles and focus', () => {
    const page = harness();
    page.click();
    assert.equal(page.body.style.position, 'fixed');
    assert.equal(page.body.style.top, '-520px');
    assert.equal(page.body.style.paddingRight, '34px');
    page.close.emit('click');
    assert.equal(page.dialog.open, false);
    assert.equal(page.player.children.length, 0);
    assert.equal(page.body.style.position, '');
    assert.equal(page.body.style.overflow, 'auto');
    assert.equal(page.body.style.paddingRight, '5px');
    assert.deepEqual(page.scrolls, [[0, 520]]);
    assert.equal(page.document.activeElement, page.trigger);
    page.click();
    assert.equal(page.created.length, 2);
});

test('Escape, native cancel and backdrop close; interacting inside the player leaves it open', () => {
    const page = harness();
    page.click();
    page.dialog.emit('click', { target: page.player });
    assert.equal(page.dialog.open, true);
    page.dialog.emit('click', { target: page.dialog });
    assert.equal(page.dialog.open, false);
    page.click();
    let prevented = false;
    page.dialog.emit('cancel', { preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(page.dialog.open, false);
    page.click();
    page.dialog.emit('keydown', { key: 'Escape', preventDefault() {} });
    assert.equal(page.dialog.open, false);
    assert.equal(page.player.children.length, 0);
});

test('unsupported browser, modified clicks and invalid IDs keep the original video link fallback', () => {
    for (const options of [{ supported: false }, { videoId: 'invalid' }]) {
        const page = harness(options);
        assert.equal(page.click(), false);
        assert.equal(page.dialog.open, false);
        assert.equal(page.created.length, 0);
    }
    const page = harness();
    assert.equal(page.click({ ctrlKey: true }), false);
    assert.equal(page.created.length, 0);
});

test('repeat initialization and clicks cannot duplicate handlers, players or scroll locks', () => {
    const page = harness();
    page.initialize();
    page.click();
    page.click();
    assert.equal(page.trigger.listeners.get('click').length, 1);
    assert.equal(page.created.length, 1);
    page.dialog.close();
    assert.equal(page.player.children.length, 0);
    assert.deepEqual(page.scrolls, [[0, 520]]);
});

test('a delayed close event from a previous opening cannot interrupt a newly opened video', () => {
    const page = harness();
    page.click();
    page.close.emit('click');
    page.click();
    const currentFrame = page.player.children[0];
    page.dialog.emit('close');
    assert.equal(page.dialog.open, true);
    assert.equal(page.player.children[0], currentFrame);
    assert.equal(page.body.style.position, 'fixed');
});
