const youtubeHosts = new Set(['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com']);

export const normalizeVideoUrl = (value) => {
    let source = String(value ?? '').trim();
    if (/^(?:(?:www\.|m\.)?youtube(?:-nocookie)?\.com|(?:www\.)?youtu\.be|(?:www\.|player\.)?vimeo\.com)\//i.test(source)) {
        source = `https://${source}`;
    }
    let url;
    try { url = new URL(source); } catch { return null; }
    if (!['https:', 'http:'].includes(url.protocol) || url.username || url.password) return null;
    const host = url.hostname.toLowerCase();
    const path = url.pathname.split('/').filter(Boolean);
    if (youtubeHosts.has(host) || ['youtu.be', 'www.youtu.be'].includes(host)) {
        const id = host.endsWith('youtu.be') ? path[0] : path[0] === 'watch' ? url.searchParams.get('v') : ['embed', 'shorts', 'live'].includes(path[0]) ? path[1] : null;
        if (!/^[A-Za-z0-9_-]{11}$/.test(id ?? '')) return null;
        const embed = new URL(`https://www.${host.includes('youtube-nocookie') ? 'youtube-nocookie' : 'youtube'}.com/embed/${id}`);
        for (const key of ['start', 'end', 'autoplay', 'controls', 'loop', 'mute', 'playsinline', 'rel']) {
            const setting = url.searchParams.get(key);
            if (setting !== null && /^\d+$/.test(setting)) embed.searchParams.set(key, setting);
        }
        if (!embed.searchParams.has('start')) {
            const time = url.searchParams.get('t') ?? url.hash.match(/^#t=(.*)$/)?.[1];
            const parts = time?.match(/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/);
            const seconds = /^\d+$/.test(time ?? '') ? Number(time) : parts ? Number(parts[1] || 0) * 3600 + Number(parts[2] || 0) * 60 + Number(parts[3] || 0) : 0;
            if (Number.isSafeInteger(seconds) && seconds > 0) embed.searchParams.set('start', String(seconds));
        }
        return embed.href;
    }
    if (['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'].includes(host)) {
        const index = host === 'player.vimeo.com' && path[0] === 'video' ? 1 : path.findLastIndex((part) => /^\d+$/.test(part));
        const id = path[index];
        if (!/^\d+$/.test(id ?? '')) return null;
        const embed = new URL(`https://player.vimeo.com/video/${id}`);
        const hash = url.searchParams.get('h') ?? path[index + 1];
        if (/^[a-f\d]+$/i.test(hash ?? '')) embed.searchParams.set('h', hash);
        for (const key of ['autoplay', 'loop', 'muted', 'title', 'byline', 'portrait']) {
            if (/^[01]$/.test(url.searchParams.get(key) ?? '')) embed.searchParams.set(key, url.searchParams.get(key));
        }
        return embed.href;
    }
    return null;
};

export const videoEmbedValue = (source) => {
    const url = normalizeVideoUrl(source);
    if (!url) return null;
    const original = new URL(/^https?:\/\//i.test(String(source).trim()) ? String(source).trim() : `https://${String(source).trim()}`);
    const portrait = original.pathname.startsWith('/shorts/');
    return { url, width: portrait ? 315 : 640, height: portrait ? 560 : 360 };
};

export const registerAdminVideoFormat = (Quill) => {
    const Video = Quill.import('formats/video');
    class AdminVideo extends Video {
        static sanitize(value) {
            // Existing valid iframe sources remain editable; new video insertion accepts the providers above.
            return super.sanitize(value);
        }
        format(name, value) {
            super.format(name, value);
            if (name === 'width' || name === 'height') {
                const width = Number(this.domNode.getAttribute('width'));
                const height = Number(this.domNode.getAttribute('height'));
                if (Number.isFinite(width) && Number.isFinite(height) && width > 0 && height > 0) {
                    this.domNode.style.aspectRatio = `${width} / ${height}`;
                } else {
                    this.domNode.style.removeProperty('aspect-ratio');
                }
            }
        }
        static create(value) {
            const embed = value && typeof value === 'object' ? value : null;
            const node = super.create(embed ? embed.url : value);
            if (embed && Number.isFinite(embed.width) && Number.isFinite(embed.height) && embed.width > 0 && embed.height > 0) {
                node.setAttribute('width', String(embed.width));
                node.setAttribute('height', String(embed.height));
                node.style.aspectRatio = `${embed.width} / ${embed.height}`;
            }
            node.setAttribute('title', 'Video');
            node.setAttribute('loading', 'lazy');
            node.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share');
            node.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
            return node;
        }
    }
    Quill.register(AdminVideo, true);
};

export const editorRange = (quill) => {
    const range = quill.getSelection() || quill.__lastRange || { index: quill.getLength() - 1, length: 0 };
    const end = Math.max(0, quill.getLength() - 1);
    const index = Math.min(Math.max(0, range.index), end);
    return { index, length: Math.min(Math.max(0, range.length || 0), end - index) };
};

export const linkDocumentToEditor = (quill, range, document, origin) => {
    const url = new URL(document.url, origin);
    if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) throw new Error('Poveznica dokumenta nije valjana.');
    quill.focus();
    if (range.length > 0) {
        quill.formatText(range.index, range.length, 'link', url.href, 'user');
        quill.setSelection(range.index + range.length, 0, 'silent');
    } else {
        const label = String(document.name || 'PDF dokument');
        quill.insertText(range.index, label, 'link', url.href, 'user');
        quill.setSelection(range.index + label.length, 0, 'silent');
    }
};

const createDialog = (title, content, restoreFocus) => {
    const dialog = document.createElement('dialog');
    dialog.className = 'admin-editor-dialog';
    const headingId = `admin-editor-dialog-${Math.random().toString(36).slice(2)}`;
    dialog.setAttribute('aria-labelledby', headingId);
    dialog.innerHTML = `<div class="admin-editor-dialog-header"><h2 id="${headingId}"></h2><button type="button" data-editor-dialog-close aria-label="Zatvori">×</button></div>${content}`;
    dialog.querySelector('h2').textContent = title;
    document.body.append(dialog);
    dialog.querySelector('[data-editor-dialog-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        dialog.remove();
        restoreFocus();
    }, { once: true });
    dialog.showModal();
    return dialog;
};

const showVideoDialog = (state) => {
    const { quill } = state;
    const range = editorRange(quill);
    const dialog = createDialog('Umetni video', `
        <form class="admin-editor-dialog-body" data-video-form>
            <p>Zalijepite poveznicu na YouTube, YouTube Shorts ili Vimeo video.</p>
            <label>Poveznica videa<input type="text" inputmode="url" name="video_url" placeholder="https://www.youtube.com/watch?v=…" required autofocus></label>
            <p class="admin-editor-dialog-status" role="status" aria-live="polite"></p>
            <div class="admin-editor-dialog-actions"><button type="submit" class="admin-editor-primary">Umetni video</button></div>
        </form>`, () => { if (state.wrapper.isConnected) quill.focus(); });
    dialog.querySelector('form').addEventListener('submit', (event) => {
        event.preventDefault();
        const source = dialog.querySelector('input').value;
        const embed = videoEmbedValue(source);
        if (!embed) {
            dialog.querySelector('[role="status"]').textContent = 'Unesite valjanu YouTube ili Vimeo poveznicu.';
            return;
        }
        if (!state.wrapper.isConnected) return dialog.close();
        const index = range.index + range.length;
        quill.insertEmbed(index, 'video', embed, 'user');
        quill.setSelection(Math.min(index + 1, quill.getLength() - 1), 0, 'silent');
        state.syncTextareaFromQuill(true);
        dialog.close();
    });
};

const responseJson = async (response) => {
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'Zahtjev nije uspio. Pokušajte ponovno.');
    return data;
};

const fileSize = (size) => `${(Number(size || 0) / 1024 / 1024).toLocaleString('hr-HR', { maximumFractionDigits: 1 })} MB`;

const showDocumentLibrary = (state) => {
    const { quill } = state;
    const range = editorRange(quill);
    const endpoint = new URL(state.textarea.dataset.documentLibraryUrl, window.location.origin);
    if (endpoint.origin !== window.location.origin) return;
    let page = 1;
    let lastPage = 1;
    let maxUploadKb = 20480;
    let loading = false;
    let uploading = false;
    let request = 0;
    let timer;
    const controller = new AbortController();
    const dialog = createDialog('PDF dokumenti', `
        <div class="admin-editor-dialog-body">
            <p data-document-selection></p>
            <form class="admin-editor-document-upload" data-document-upload>
                <h3>Učitaj novi dokument</h3>
                <label>PDF datoteka<input type="file" name="file" accept="application/pdf,.pdf" required></label>
                <label>Nova mapa (neobvezno)<input type="text" name="folder" maxlength="80" placeholder="Ili upotrijebi odabranu mapu"></label>
                <p data-document-limit></p>
                <button type="submit" class="admin-editor-primary">Učitaj i poveži</button>
            </form>
            <div class="admin-editor-library-filters">
                <label>Mapa<select data-document-folder><option value="">Sve mape</option></select></label>
                <label>Pretraži dokumente<input type="search" data-document-search placeholder="Naziv dokumenta"></label>
            </div>
            <p class="admin-editor-dialog-status" role="status" aria-live="polite"></p>
            <ul class="admin-editor-document-list" aria-label="Dostupni PDF dokumenti"></ul>
            <div class="admin-editor-library-pagination"><button type="button" data-document-previous>Prethodna</button><span data-document-page></span><button type="button" data-document-next>Sljedeća</button></div>
        </div>`, () => { if (state.wrapper.isConnected) quill.focus(); });
    const status = dialog.querySelector('[role="status"]');
    const folder = dialog.querySelector('[data-document-folder]');
    const search = dialog.querySelector('[data-document-search]');
    const uploadForm = dialog.querySelector('[data-document-upload]');
    const uploadButton = uploadForm.querySelector('button');
    const closeButton = dialog.querySelector('[data-editor-dialog-close]');
    const previous = dialog.querySelector('[data-document-previous]');
    const next = dialog.querySelector('[data-document-next]');
    const selectedText = range.length ? quill.getText(range.index, range.length).trim() : '';
    dialog.querySelector('[data-document-selection]').textContent = selectedText ? `Dokument će biti povezan s tekstom: „${selectedText.slice(0, 120)}”` : 'Odaberite dokument. Njegov naziv umetnut ćemo kao poveznicu u tekst.';
    const updateControls = () => {
        previous.disabled = loading || uploading || page <= 1;
        next.disabled = loading || uploading || page >= lastPage;
        uploadButton.disabled = uploading || loading;
        closeButton.disabled = uploading;
        dialog.querySelectorAll('.admin-editor-document-list button').forEach((button) => { button.disabled = uploading; });
        folder.disabled = uploading;
        search.disabled = uploading;
        uploadButton.textContent = uploading ? 'Učitavanje…' : 'Učitaj i poveži';
        dialog.querySelector('[data-document-limit]').textContent = `Samo PDF, najviše ${fileSize(maxUploadKb * 1024)}. Dokument ostaje dostupan u zajedničkoj biblioteci.`;
    };
    const chooseDocument = (item) => {
        if (!dialog.isConnected) return;
        if (!state.wrapper.isConnected) return dialog.close();
        try {
            linkDocumentToEditor(quill, range, item, window.location.origin);
            state.syncTextareaFromQuill(true);
            dialog.close();
        } catch (error) { status.textContent = error.message; }
    };
    const load = async () => {
        const current = ++request;
        loading = true;
        status.textContent = 'Učitavanje dokumenata…';
        dialog.querySelector('ul').replaceChildren();
        updateControls();
        const url = new URL(endpoint);
        if (folder.value) url.searchParams.set('folder', folder.value);
        if (search.value.trim()) url.searchParams.set('q', search.value.trim());
        url.searchParams.set('page', String(page));
        try {
            const data = await responseJson(await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: controller.signal }));
            if (current !== request || !dialog.isConnected) return;
            const selectedFolder = folder.value;
            folder.replaceChildren(new Option('Sve mape', ''), ...data.folders.map((name) => new Option(name, name)));
            folder.value = selectedFolder;
            page = data.page;
            lastPage = data.last_page;
            maxUploadKb = data.max_upload_kb;
            dialog.querySelector('[data-document-page]').textContent = data.total ? `${page} / ${lastPage} · ${data.total} dokumenata` : '';
            status.textContent = data.documents.length ? '' : 'Nema dokumenata za odabrani prikaz.';
            for (const item of data.documents) {
                const row = document.createElement('li');
                const copy = document.createElement('div');
                const name = document.createElement('strong');
                name.textContent = item.name;
                const meta = document.createElement('small');
                meta.textContent = `${item.folder} · ${fileSize(item.size)}`;
                copy.append(name, meta);
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = 'Poveži';
                button.setAttribute('aria-label', `Poveži dokument ${item.name}`);
                button.addEventListener('click', () => { if (!uploading) chooseDocument(item); });
                row.append(copy, button);
                dialog.querySelector('ul').append(row);
            }
        } catch (error) {
            if (current === request && error.name !== 'AbortError') status.textContent = error.message;
        } finally {
            if (current === request) { loading = false; updateControls(); }
        }
    };
    folder.addEventListener('change', () => { page = 1; load(); });
    search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => { page = 1; load(); }, 250); });
    previous.addEventListener('click', () => { page--; load(); });
    next.addEventListener('click', () => { page++; load(); });
    dialog.addEventListener('cancel', (event) => { if (uploading) event.preventDefault(); });
    dialog.addEventListener('close', () => { clearTimeout(timer); controller.abort(); }, { once: true });
    uploadForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const file = uploadForm.querySelector('input[type="file"]').files?.[0];
        if (!file || uploading) return;
        if (!/\.pdf$/i.test(file.name) || file.size > maxUploadKb * 1024) {
            status.textContent = `Odaberite PDF datoteku do ${fileSize(maxUploadKb * 1024)}.`;
            return;
        }
        const body = new FormData();
        body.append('file', file);
        body.append('folder', uploadForm.querySelector('input[name="folder"]').value.trim() || folder.value || 'opcenito');
        uploading = true;
        status.textContent = 'Učitavanje dokumenta…';
        updateControls();
        try {
            const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const data = await responseJson(await fetch(endpoint, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token }, signal: controller.signal }));
            chooseDocument(data.document);
        } catch (error) {
            if (error.name !== 'AbortError') status.textContent = error.message;
        } finally { uploading = false; updateControls(); }
    });
    load();
};

export const setupRichTextMediaTools = (state) => {
    const toolbar = state.quill.getModule('toolbar');
    toolbar.addHandler('video', () => showVideoDialog(state));
    toolbar.container.querySelector('.ql-video')?.setAttribute('title', 'Umetni YouTube ili Vimeo video');
    if (!state.textarea.dataset.documentLibraryUrl) return;
    const group = document.createElement('span');
    group.className = 'ql-formats';
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'admin-quill-document-button';
    button.textContent = 'PDF dokument';
    button.title = 'Učitaj ili odaberi PDF dokument i poveži ga s označenim tekstom';
    button.addEventListener('click', () => showDocumentLibrary(state));
    group.append(button);
    toolbar.container.append(group);
};

export const initRichTextHtmlModes = (root = document) => {
    const wrappers = root.matches?.('[data-rich-text-html-mode]') ? [root] : [];
    wrappers.push(...(root.querySelectorAll?.('[data-rich-text-html-mode]') || []));
    for (const wrapper of wrappers) {
        if (wrapper.__richTextHtmlModeBound) continue;
        wrapper.__richTextHtmlModeBound = true;
        const parts = () => {
            const textarea = wrapper.querySelector('textarea[data-quill-editor]');
            return { textarea, state: textarea?.__adminQuillState, source: wrapper.querySelector('[data-html-mode-source]'), open: wrapper.querySelector('[data-html-mode-open]'), apply: wrapper.querySelector('[data-html-mode-apply]') };
        };
        const syncSource = ({ textarea, source }) => {
            textarea.value = source.value;
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
        };
        wrapper.addEventListener('input', (event) => {
            if (event.target.matches('[data-html-mode-source]')) syncSource(parts());
        });
        wrapper.addEventListener('click', (event) => {
            const enter = event.target.closest('[data-html-mode-open]');
            const leave = event.target.closest('[data-html-mode-apply]');
            if (!enter && !leave) return;
            const field = parts();
            if (!field.state || !field.source) return;
            if (enter) {
                field.source.value = field.textarea.value || '';
                field.state.htmlSourceMode = true;
                field.source.classList.remove('hidden');
                field.state.wrapper.classList.add('hidden');
                field.open.classList.add('hidden');
                field.apply.classList.remove('hidden');
                field.source.focus();
            } else {
                field.textarea.dataset.quillBypassInlineSanitizeOnce = '1';
                field.state.htmlSourceMode = false;
                syncSource(field);
                field.textarea.dispatchEvent(new Event('change', { bubbles: true }));
                field.source.classList.add('hidden');
                field.state.wrapper.classList.remove('hidden');
                field.apply.classList.add('hidden');
                field.open.classList.remove('hidden');
            }
        });
    }
};
