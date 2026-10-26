(() => {
    if (!document.body.classList.contains('watchline-storefront') || window.__watchlineHomeCarouselsInitialized) return;
    window.__watchlineHomeCarouselsInitialized = true;

    const selector = '[data-herrera-home-carousel]';
    const states = new Map();
    let observer;
    let paused = false;
    let retryTimer = 0;
    let retryCount = 0;

    const clearRetry = () => {
        window.clearInterval(retryTimer);
        retryTimer = 0;
    };

    const mount = (state) => {
        const element = state.element;
        if (paused || state.mounting || state.failed || state.instance || element.dataset.splideReady === '1'
            || typeof window.Splide !== 'function' || element.isConnected === false) return;

        state.mounting = true;
        const controls = Array.from(element.querySelectorAll('a, button, textarea, input, select, iframe'))
            .map((node) => [node, node.getAttribute('tabindex')]);
        const options = element.matches('[data-continuous-card-carousel]')
            ? { speed: 420, easing: 'cubic-bezier(0.25, 1, 0.5, 1)' } : {};
        const track = element.querySelector('.splide__track');
        if (state.start !== null) {
            options.start = state.start;
        } else if (track && track.scrollLeft > 0) {
            const slides = Array.from(element.querySelectorAll('.splide__slide'));
            options.start = slides.reduce((nearest, slide, index) => Math.abs(slide.offsetLeft - track.scrollLeft)
                < Math.abs(slides[nearest].offsetLeft - track.scrollLeft) ? index : nearest, 0);
        }
        // Native scrolling remains available before the slider is needed.
        // Splide takes over with its transform, keeping the same visible item.
        if (track) track.scrollLeft = 0;

        let instance;
        try {
            instance = new window.Splide(element, options);
            instance.mount({
                RestoreCardGridFocus: () => ({
                    destroy: () => controls.forEach(([node, tabindex]) => {
                        if (tabindex === null) node.removeAttribute('tabindex');
                        else node.setAttribute('tabindex', tabindex);
                    }),
                }),
            });
            state.instance = instance;
            element.dataset.splideReady = '1';
            observer?.unobserve(element);
            state.moves.splice(0).forEach((direction) => instance.go(direction));
        } catch {
            state.failed = true;
            try { instance?.destroy(true); } catch { /* Keep the native card row available. */ }
        } finally {
            state.mounting = false;
        }
    };

    const flushRequested = () => {
        if (typeof window.Splide !== 'function') return false;
        clearRetry();
        for (const state of states.values()) {
            if (state.requested) mount(state);
        }
        return true;
    };

    const awaitLibrary = () => {
        if (paused || retryTimer || flushRequested()) return;
        retryCount = 0;
        retryTimer = window.setInterval(() => {
            if (flushRequested() || ++retryCount >= 40) clearRetry();
        }, 120);
    };

    const requestMount = (state) => {
        state.requested = true;
        mount(state);
        if (!state.instance) awaitLibrary();
    };

    const observePending = () => {
        for (const state of states.values()) {
            if (!state.instance && !state.failed && state.element.dataset.splideReady !== '1') {
                if (observer) observer.observe(state.element);
                else requestMount(state);
            }
        }
    };

    const start = () => {
        if (typeof window.IntersectionObserver === 'function') {
            observer = new window.IntersectionObserver((entries) => {
                if (paused) return;
                entries.forEach((entry) => {
                    if (entry.isIntersecting) requestMount(states.get(entry.target));
                });
            }, { rootMargin: '500px 0px', threshold: 0 });
        }
        document.querySelectorAll(selector).forEach((element) => {
            if (element.dataset.splideReady === '1') return;
            const state = { element, requested: false, mounting: false, failed: false, instance: null, start: null, moves: [] };
            states.set(element, state);
            element.addEventListener('focusin', (event) => {
                if (state.instance) return;
                const slide = event.target.closest('.splide__slide');
                const index = slide ? Array.from(element.querySelectorAll('.splide__slide')).indexOf(slide) : -1;
                if (index >= 0) state.start = index;
                requestMount(state);
            }, true);
            for (const type of ['pointerdown', 'touchstart']) {
                element.addEventListener(type, () => requestMount(state), { capture: true, passive: true });
            }
            element.addEventListener('click', (event) => {
                if (state.instance || element.dataset.splideReady === '1') return;
                const arrow = event.target.closest('.splide__arrow');
                if (arrow && element.contains(arrow)) {
                    // A keyboard/programmatic first click may have no pointerdown.
                    // Replay it once after mounting instead of losing or doubling it.
                    event.preventDefault();
                    event.stopPropagation();
                    state.moves.push(arrow.classList.contains('splide__arrow--prev') ? '<' : '>');
                }
                requestMount(state);
            }, true);
        });
        observePending();
        document.querySelector('[data-storefront-splide]')?.addEventListener('load', flushRequested);
        awaitLibrary();
    };

    window.addEventListener('pagehide', () => {
        paused = true;
        clearRetry();
        observer?.disconnect();
    });
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        paused = false;
        states.forEach((state) => state.instance?.refresh());
        observePending();
        awaitLibrary();
    });
    window.addEventListener('orientationchange', () => {
        // Mounted sliders already handle resize and their responsive breakpoints.
        // Reobserve untouched rows so their new viewport position is considered.
        if (!paused) observePending();
    });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
})();
