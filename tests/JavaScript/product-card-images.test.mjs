import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../../public/front-theme/scripts/product-card-images.js', import.meta.url), 'utf8');

function page(states, { hover = true } = {}) {
    class Image {
        constructor(state) {
            Object.assign(this, { complete: false, naturalWidth: 0 }, state);
            this.frame = { dataset: {} };
        }
        matches(selector) { return selector === 'img[data-product-card-image]'; }
        closest() { return this.frame; }
    }
    const images = states.map((state) => new Image(state));
    const listeners = new Map();
    const document = {
        readyState: 'complete',
        querySelectorAll: () => images,
        addEventListener(type, listener) { listeners.set(type, listener); },
    };
    vm.runInNewContext(script, { document, HTMLImageElement: Image, window: { matchMedia: () => ({ matches: hover }) } });
    return { images, Image, emit: (type, event) => listeners.get(type)(event) };
}

test('pending images reveal after decoding while cached images skip the placeholder', async () => {
    let finishDecode;
    const decodePromise = new Promise((resolve) => { finishDecode = resolve; });
    const { images, emit } = page([
        { decode: () => decodePromise },
        { complete: true, naturalWidth: 480 },
    ]);
    assert.equal(images[0].frame.dataset.imageState, 'loading');
    assert.equal(images[1].frame.dataset.imageState, 'loaded');
    images[0].naturalWidth = 480;
    emit('load', { target: images[0] });
    assert.equal(images[0].frame.dataset.imageState, 'loading');
    finishDecode();
    await decodePromise;
    await Promise.resolve();
    assert.equal(images[0].frame.dataset.imageState, 'loaded');
});

function secondaryImage() {
    const listeners = new Map();
    const writes = [];
    const frame = { dataset: {} };
    const image = {
        dataset: { hoverSrc: '/original.jpg', hoverSrcset: '/small.webp 320w, /original.jpg 1000w' },
        complete: false, naturalWidth: 0,
        closest: () => frame,
        addEventListener: (type, listener) => listeners.set(type, listener),
        removeAttribute(name) {
            delete this.dataset[name === 'data-hover-src' ? 'hoverSrc' : 'hoverSrcset'];
        },
        set srcset(value) { writes.push(['srcset', value]); },
        set src(value) { writes.push(['src', value]); },
    };
    const card = { querySelector: () => image.dataset.hoverSrc ? image : null };
    return { image, writes, frame, target: { closest: () => card }, load: () => listeners.get('load')() };
}

test('secondary originals wait for hover, select one responsive candidate, and reveal only after a successful load', () => {
    const { emit } = page([]);
    const secondary = secondaryImage();
    assert.equal(secondary.writes.length, 0);
    emit('pointerover', { type: 'pointerover', target: secondary.target });
    assert.deepEqual(secondary.writes, [
        ['srcset', '/small.webp 320w, /original.jpg 1000w'], ['src', '/original.jpg'],
    ]);
    assert.equal(secondary.frame.dataset.hoverImageReady, undefined);
    emit('pointerover', { type: 'pointerover', target: secondary.target });
    assert.equal(secondary.writes.length, 2);
    secondary.load();
    assert.equal(secondary.frame.dataset.hoverImageReady, undefined, 'failed images never hide the primary');
    secondary.image.naturalWidth = 1000;
    secondary.load();
    assert.equal(secondary.frame.dataset.hoverImageReady, '1');
});

test('touch pointer events do not fetch hidden originals, while keyboard focus also works for newly appended cards', () => {
    const { emit } = page([], { hover: false });
    const secondary = secondaryImage();
    emit('pointerover', { type: 'pointerover', target: secondary.target });
    assert.equal(secondary.writes.length, 0);
    emit('focusin', { type: 'focusin', target: secondary.target });
    assert.equal(secondary.writes.length, 2);
    emit('focusin', { type: 'focusin', target: secondary.target });
    assert.equal(secondary.writes.length, 2);
});

test('failed images and decode failures cannot leave a permanent loading state', async () => {
    const { images, emit } = page([
        {}, { complete: true },
        { decode: () => Promise.reject(new Error('decode unavailable')) },
    ]);
    emit('error', { target: images[0] });
    assert.equal(images[0].frame.dataset.imageState, 'error');
    assert.equal(images[1].frame.dataset.imageState, 'error');
    images[2].naturalWidth = 480;
    emit('load', { target: images[2] });
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(images[2].frame.dataset.imageState, 'loaded');
});

test('appended and carousel-cloned images use the same captured load handling', () => {
    const { Image, emit } = page([]);
    const image = new Image({});
    emit('catalog:items-appended', { detail: { container: { querySelectorAll: () => [image] } } });
    assert.equal(image.frame.dataset.imageState, 'loading');
    image.naturalWidth = 480;
    emit('load', { target: image });
    assert.equal(image.frame.dataset.imageState, 'loaded');
    const clone = new Image({ naturalWidth: 480 });
    clone.frame.dataset.imageState = 'loading';
    emit('load', { target: clone });
    assert.equal(clone.frame.dataset.imageState, 'loaded');
    emit('load', { target: {} });
});
