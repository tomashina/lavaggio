import test from 'node:test';
import assert from 'node:assert/strict';
import { normalizeVideoUrl, videoEmbedValue, linkDocumentToEditor, editorRange, registerAdminVideoFormat, insertImageToEditor, richTextEditorHtml } from '../../resources/js/admin-rich-text-media.js';

test('video links support YouTube, Shorts, private embeds and Vimeo with start positions', () => {
    for (const [input, expected] of [
        ['https://www.youtube.com/watch?v=7nMlEAlj_Qo&feature=share', 'https://www.youtube.com/embed/7nMlEAlj_Qo'],
        ['https://www.youtube.com/shorts/7nMlEAlj_Qo?si=tracking', 'https://www.youtube.com/embed/7nMlEAlj_Qo'],
        ['youtu.be/7nMlEAlj_Qo?t=1m30s', 'https://www.youtube.com/embed/7nMlEAlj_Qo?start=90'],
        ['https://www.youtube-nocookie.com/embed/7nMlEAlj_Qo?controls=0&start=12', 'https://www.youtube-nocookie.com/embed/7nMlEAlj_Qo?start=12&controls=0'],
        ['https://www.youtube.com/live/7nMlEAlj_Qo#t=42', 'https://www.youtube.com/embed/7nMlEAlj_Qo?start=42'],
        ['https://vimeo.com/123456789', 'https://player.vimeo.com/video/123456789'],
        ['https://vimeo.com/123456789/ab12cd', 'https://player.vimeo.com/video/123456789?h=ab12cd'],
        ['https://player.vimeo.com/video/123456789?h=ab12cd&autoplay=1', 'https://player.vimeo.com/video/123456789?h=ab12cd&autoplay=1'],
    ]) assert.equal(normalizeVideoUrl(input), expected, input);
});

test('new videos carry their dimensions atomically while Shorts remain portrait', () => {
    assert.deepEqual(videoEmbedValue('https://www.youtube.com/shorts/7nMlEAlj_Qo'), { url: 'https://www.youtube.com/embed/7nMlEAlj_Qo', width: 315, height: 560 });
    assert.deepEqual(videoEmbedValue('https://youtu.be/7nMlEAlj_Qo'), { url: 'https://www.youtube.com/embed/7nMlEAlj_Qo', width: 640, height: 360 });
    assert.deepEqual(videoEmbedValue('https://vimeo.com/123456789'), { url: 'https://player.vimeo.com/video/123456789', width: 640, height: 360 });
    assert.equal(videoEmbedValue('javascript:alert(1)'), null);
});

test('new video insertion rejects unsupported hosts, broken IDs and unsafe URL schemes', () => {
    for (const input of [
        'javascript:alert(1)', 'data:text/html,test', 'https://youtube.com.evil.invalid/watch?v=7nMlEAlj_Qo',
        'https://youtube.com@evil.invalid/watch?v=7nMlEAlj_Qo', 'https://user:secret@youtube.com/watch?v=7nMlEAlj_Qo',
        'https://www.youtube.com/watch?v=missing', 'https://vimeo.com/not-a-video', '',
    ]) assert.equal(normalizeVideoUrl(input), null, input);
});

test('video format keeps valid legacy iframe sources and native width/height support', () => {
    const formats = { height: '560', width: '315' };
    class ExistingVideo {
        format(name, value) { this.domNode.setAttribute(name, value); }
        static formats() { return formats; }
        static sanitize(url) { return /^(https?:)/.test(url) ? url : 'about:blank'; }
        static create(value) {
            const attrs = { src: this.sanitize(value) };
            return { attrs, style: { removeProperty() {} }, getAttribute(key) { return attrs[key]; }, setAttribute(key, val) { attrs[key] = val; } };
        }
    }
    let Video;
    registerAdminVideoFormat({ import: () => ExistingVideo, register: (type) => { Video = type; } });
    const node = Video.create('https://www.youtube.com/embed/7nMlEAlj_Qo');
    assert.equal(node.attrs.src, 'https://www.youtube.com/embed/7nMlEAlj_Qo');
    assert.deepEqual(Video.formats(), formats);
    const inserted = Video.create(videoEmbedValue('https://www.youtube.com/shorts/7nMlEAlj_Qo'));
    assert.equal(inserted.attrs.src, 'https://www.youtube.com/embed/7nMlEAlj_Qo');
    assert.equal(inserted.attrs.width, '315');
    assert.equal(inserted.attrs.height, '560');
    assert.equal(inserted.style.aspectRatio, '315 / 560');
    const portrait = new Video();
    portrait.domNode = node;
    portrait.format('width', '315');
    portrait.format('height', '560');
    assert.equal(node.style.aspectRatio, '315 / 560');
    assert.equal(node.attrs.width, '315');
    assert.equal(node.attrs.height, '560');
    const legacy = 'https://www.youtube.com/embed/7nMlEAlj_Qo?loop=1&playlist=7nMlEAlj_Qo&enablejsapi=1&origin=https%3A%2F%2Fshop.lavaggio.hr';
    assert.equal(Video.sanitize(legacy), legacy);
    assert.equal(Video.sanitize('https://existing-video.example/embed/123'), 'https://existing-video.example/embed/123');
    assert.equal(Video.sanitize('javascript:alert(1)'), 'about:blank');
});

const fakeEditor = () => {
    const calls = [];
    return { calls, focus() {}, getSelection: () => null, getLength: () => 21,
        formatText(...args) { calls.push(['formatText', ...args]); },
        insertText(...args) { calls.push(['insertText', ...args]); },
        updateContents(...args) { calls.push(['updateContents', ...args]); },
        setSelection(...args) { calls.push(['setSelection', ...args]); },
    };
};

test('PDF library links the saved selection without replacing its words', () => {
    const editor = fakeEditor();
    editor.__lastRange = { index: 3, length: 8 };
    const range = editorRange(editor);
    linkDocumentToEditor(editor, range, { name: 'Cjenik.pdf', url: '/storage/content-documents/cjenici/a.pdf' }, 'https://shop.lavaggio.hr');
    assert.deepEqual(editor.calls, [
        ['formatText', 3, 8, 'link', 'https://shop.lavaggio.hr/storage/content-documents/cjenici/a.pdf', 'user'],
        ['setSelection', 11, 0, 'silent'],
    ]);
});

test('PDF library inserts a document name at the caret and rejects executable URLs', () => {
    const editor = fakeEditor();
    linkDocumentToEditor(editor, { index: 4, length: 0 }, { name: 'Cjenik.pdf', url: '/storage/a.pdf' }, 'https://shop.lavaggio.hr');
    assert.deepEqual(editor.calls[0], ['insertText', 4, 'Cjenik.pdf', 'link', 'https://shop.lavaggio.hr/storage/a.pdf', 'user']);
    assert.throws(() => linkDocumentToEditor(editor, { index: 0, length: 1 }, { url: 'javascript:alert(1)' }, 'https://shop.lavaggio.hr'));
});

test('image insertion saves a public image with its description and dimensions', () => {
    const editor = fakeEditor();
    const image = { url: 'https://shop.lavaggio.hr/storage/content-images/photo.png', width: 320, height: 240 };
    insertImageToEditor(editor, { index: 4, length: 3 }, image, 'Fotografija', 'https://shop.lavaggio.hr');
    assert.deepEqual(editor.calls, [
        ['updateContents', { ops: [
            { retain: 7 },
            { insert: { image: '/storage/content-images/photo.png' }, attributes: { alt: 'Fotografija', width: '320', height: '240' } },
        ] }, 'user'],
        ['setSelection', 8, 0, 'silent'],
    ]);
    assert.throws(() => insertImageToEditor(editor, { index: 0, length: 0 }, { url: 'javascript:alert(1)' }, '', 'https://shop.lavaggio.hr'));
});

test('saved lists use semantic HTML while other blocks preserve their editor markup', () => {
    const list = { tagName: 'OL', outerHTML: '<ol><li data-list="bullet">A</li><li data-list="ordered">B</li></ol>' };
    const iframe = { tagName: 'IFRAME', outerHTML: '<iframe class="ql-video" src="https://www.youtube.com/embed/7nMlEAlj_Qo"></iframe>' };
    const listBlot = { statics: { blotName: 'list-container' }, length: () => 4 };
    const editor = {
        root: { querySelector: () => list, children: [list, iframe] },
        getIndex: () => 0,
        getSemanticHTML(index, length) {
            assert.deepEqual([index, length], [0, 4]);
            return '<ul><li>A</li></ul><ol><li>B</li></ol>';
        },
    };
    assert.equal(richTextEditorHtml(editor, (node) => node === list ? listBlot : null), '<ul><li>A</li></ul><ol><li>B</li></ol>' + iframe.outerHTML);
});
