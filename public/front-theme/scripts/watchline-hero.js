(() => {
    const init = () => {
        if (typeof window.Splide !== 'function') return;
        document.querySelectorAll('[data-watchline-hero]').forEach((hero) => {
            const carousel = hero.querySelector('[data-watchline-hero-carousel]');
            if (!carousel || carousel.dataset.watchlineReady === '1') return;
            const controls = hero.querySelector('[data-watchline-hero-controls]');
            const toggle = hero.querySelector('[data-watchline-hero-toggle]');
            const current = hero.querySelector('[data-watchline-hero-current]');
            const status = hero.querySelector('[data-watchline-hero-status]');
            const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
            let paused = motion.matches;
            let announce = false;
            const slider = new window.Splide(carousel);
            const updateToggle = () => {
                if (!toggle) return;
                toggle.setAttribute('aria-pressed', String(paused));
                toggle.setAttribute('aria-label', paused ? 'Pokreni automatsku izmjenu satova' : 'Zaustavi automatsku izmjenu satova');
                toggle.querySelector('[data-watchline-hero-toggle-label]').textContent = paused ? 'Pokreni' : 'Pauza';
                toggle.querySelector('[data-watchline-hero-toggle-icon]').textContent = paused ? '▷' : 'Ⅱ';
            };
            slider.on('moved', (index) => {
                if (current) current.textContent = String(index + 1).padStart(2, '0');
                if (announce && status) {
                    const title = carousel.querySelectorAll('.wl-hero-title')[index];
                    status.textContent = `${index + 1} od ${slider.length}: ${title?.textContent.trim() || ''}`;
                }
                announce = false;
            });
            slider.on('drag', () => { announce = true; });
            carousel.addEventListener('keydown', (event) => {
                if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') announce = true;
            });
            slider.mount();
            carousel.dataset.watchlineReady = '1';
            if (controls) controls.hidden = false;
            hero.querySelector('[data-watchline-hero-prev]')?.addEventListener('click', () => { announce = true; slider.go('<'); });
            hero.querySelector('[data-watchline-hero-next]')?.addEventListener('click', () => { announce = true; slider.go('>'); });
            toggle?.addEventListener('click', () => {
                paused = !paused;
                if (paused) slider.Components.Autoplay.pause();
                else slider.Components.Autoplay.play();
                updateToggle();
            });
            motion.addEventListener('change', () => {
                if (motion.matches) {
                    paused = true;
                    slider.Components.Autoplay.pause();
                    updateToggle();
                }
            });
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) slider.Components.Autoplay.pause();
                else if (!paused) slider.Components.Autoplay.play();
            });
            updateToggle();
        });
    };
    document.addEventListener('DOMContentLoaded', init, { once: true });
    window.addEventListener('load', init, { once: true });
    document.querySelector('[data-storefront-splide]')?.addEventListener('load', init, { once: true });
    init();
})();
