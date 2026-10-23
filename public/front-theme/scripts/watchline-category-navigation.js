(() => {
    const init = () => {
        const entries = Array.from(document.querySelectorAll('[data-watchline-nav-item]'));
        const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)');
        const desktopViewport = window.matchMedia('(min-width: 1024px)');
        const controls = (entry) => ({
            toggle: entry.querySelector('[data-watchline-category-toggle]'),
            panel: entry.querySelector('[data-watchline-nav-panel]'),
        });
        const close = (entry, restoreFocus = false) => {
            const { toggle, panel } = controls(entry);
            if (!toggle || !panel) return;
            panel.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
            entry.classList.remove('is-open');
            if (restoreFocus) toggle.focus();
        };
        const open = (entry) => {
            entries.forEach((other) => { if (other !== entry) close(other); });
            const { toggle, panel } = controls(entry);
            if (!toggle || !panel) return;
            panel.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
            entry.classList.add('is-open');
        };
        entries.forEach((entry) => {
            if (entry.dataset.watchlineNavReady === '1') return;
            entry.dataset.watchlineNavReady = '1';
            const { toggle, panel } = controls(entry);
            if (!toggle || !panel) return;
            const links = () => Array.from(panel.querySelectorAll('a[href]'));
            const supportsHover = () => finePointer.matches && desktopViewport.matches
                && entry.closest('[data-watchline-category-nav]')?.dataset.watchlineCategoryNav === 'desktop';
            toggle.addEventListener('click', () => panel.hidden ? open(entry) : close(entry));
            entry.addEventListener('pointerenter', (event) => {
                if (supportsHover() && event.pointerType !== 'touch') open(entry);
            });
            entry.addEventListener('pointerleave', () => {
                if (supportsHover() && !entry.contains(document.activeElement)) close(entry);
            });
            entry.addEventListener('focusout', () => {
                window.setTimeout(() => {
                    if (!entry.contains(document.activeElement) && !entry.matches(':hover')) close(entry);
                }, 0);
            });
            entry.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && !panel.hidden) {
                    event.preventDefault();
                    close(entry, true);
                    return;
                }
                const insidePanel = panel.contains(event.target);
                if (!insidePanel && ['ArrowDown', 'ArrowUp'].includes(event.key)) {
                    event.preventDefault();
                    open(entry);
                    const targets = links();
                    (event.key === 'ArrowUp' ? targets.at(-1) : targets[0])?.focus();
                } else if (insidePanel && ['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
                    event.preventDefault();
                    const targets = links();
                    const index = targets.indexOf(document.activeElement);
                    const next = event.key === 'Home' ? 0 : event.key === 'End' ? targets.length - 1
                        : (index + (event.key === 'ArrowUp' ? -1 : 1) + targets.length) % targets.length;
                    targets[next]?.focus();
                }
            });
        });
        if (document.body.dataset.watchlineCategoryEvents === '1') return;
        document.body.dataset.watchlineCategoryEvents = '1';
        document.addEventListener('pointerdown', (event) => {
            entries.forEach((entry) => { if (!entry.contains(event.target)) close(entry); });
        });
        document.querySelectorAll('[data-mobile-menu-open]').forEach((button) => button.addEventListener('click', () => entries.forEach((entry) => close(entry))));
        desktopViewport.addEventListener('change', () => entries.forEach((entry) => close(entry)));
        window.addEventListener('pageshow', () => entries.forEach((entry) => close(entry)));
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();
