(() => {
    document.querySelectorAll('[data-lavaggio-video]').forEach((root) => {
        if (root.dataset.videoBound === '1' || !/^[A-Za-z0-9_-]{11}$/.test(root.dataset.videoId || '')) return;
        const trigger = root.querySelector('[data-lavaggio-video-open]');
        const dialog = root.querySelector('[data-lavaggio-video-dialog]');
        const player = root.querySelector('[data-lavaggio-video-player]');
        const closeButton = root.querySelector('[data-lavaggio-video-close]');
        if (!trigger || !dialog || !player || !closeButton || typeof dialog.showModal !== 'function') return;
        root.dataset.videoBound = '1';

        let pageState = null;
        const unlock = () => {
            player.replaceChildren();
            if (!pageState) return;
            const state = pageState;
            pageState = null;
            Object.entries(state.styles).forEach(([property, value]) => { document.body.style[property] = value; });
            const behavior = document.documentElement.style.scrollBehavior;
            document.documentElement.style.scrollBehavior = 'auto';
            window.scrollTo(state.x, state.y);
            document.documentElement.style.scrollBehavior = behavior;
            trigger.focus({ preventScroll: true });
        };
        const close = () => {
            if (dialog.open) dialog.close();
            unlock();
        };
        trigger.addEventListener('click', (event) => {
            if (event.defaultPrevented || event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            if (dialog.open) { event.preventDefault(); return; }
            try { dialog.showModal(); } catch (_) { return; }
            event.preventDefault();

            const properties = ['overflow', 'position', 'top', 'left', 'width', 'paddingRight'];
            pageState = { x: window.scrollX, y: window.scrollY, styles: {} };
            properties.forEach((property) => { pageState.styles[property] = document.body.style[property]; });
            const scrollbar = Math.max(0, window.innerWidth - document.documentElement.clientWidth);
            const padding = Number.parseFloat(window.getComputedStyle(document.body).paddingRight) || 0;
            Object.assign(document.body.style, { overflow: 'hidden', position: 'fixed', top: `${-pageState.y}px`, left: `${-pageState.x}px`, width: '100%' });
            if (scrollbar) document.body.style.paddingRight = `${padding + scrollbar}px`;

            const frame = document.createElement('iframe');
            frame.src = `https://www.youtube-nocookie.com/embed/${root.dataset.videoId}?autoplay=1&playsinline=1&rel=0`;
            frame.title = trigger.getAttribute('aria-label') || 'Lavaggio video';
            frame.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
            frame.allowFullscreen = true;
            frame.referrerPolicy = 'strict-origin-when-cross-origin';
            player.replaceChildren(frame);
            closeButton.focus({ preventScroll: true });
        });
        closeButton.addEventListener('click', close);
        dialog.addEventListener('cancel', (event) => { event.preventDefault(); close(); });
        dialog.addEventListener('close', () => { if (!dialog.open) unlock(); });
        dialog.addEventListener('click', (event) => { if (event.target === dialog) close(); });
        dialog.addEventListener('keydown', (event) => {
            if (!dialog.open) return;
            if (event.key === 'Escape') { event.preventDefault(); close(); }
        });
    });
})();
