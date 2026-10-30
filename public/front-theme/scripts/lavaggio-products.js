(() => {
    const panels = Array.from(document.querySelectorAll('[data-lavaggio-legal-panel]'));
    const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)');
    const controls = panels.map((panel) => {
        const summary = panel.querySelector('summary');
        let expanded = panel.open;
        let animation = null;
        panel.dataset.expanded = String(expanded);

        const setExpanded = (nextExpanded) => {
            if (expanded === nextExpanded) return;

            const startHeight = panel.getBoundingClientRect().height;
            expanded = nextExpanded;
            panel.dataset.expanded = String(expanded);
            if (animation) {
                animation.onfinish = null;
                animation.cancel();
                animation = null;
            }

            if (reducedMotion?.matches || !panel.animate) {
                panel.open = expanded;
                panel.classList.remove('is-sliding');
                return;
            }

            // Keep the content rendered until closing has finished, and measure
            // its natural height again when reversing an in-progress slide.
            panel.open = true;
            const endHeight = expanded
                ? panel.getBoundingClientRect().height
                : summary.getBoundingClientRect().height + panel.offsetHeight - panel.clientHeight;
            panel.classList.add('is-sliding');
            const slide = panel.animate([
                { height: `${startHeight}px` },
                { height: `${endHeight}px` },
            ], { duration: 300, easing: 'cubic-bezier(.25, .8, .25, 1)' });
            animation = slide;
            slide.onfinish = () => {
                if (animation !== slide) return;
                panel.open = expanded;
                animation = null;
                panel.classList.remove('is-sliding');
                slide.cancel();
            };
        };

        summary.addEventListener('click', (event) => {
            event.preventDefault();
            const nextExpanded = !expanded;
            if (nextExpanded) controls.forEach((other) => { if (other.panel !== panel) other.setExpanded(false); });
            setExpanded(nextExpanded);
        });

        panel.addEventListener('toggle', () => {
            // Native changes (for example browser find-in-page) retain their
            // normal behavior without interrupting a slide we started.
            if (animation || panel.open === expanded) return;
            expanded = panel.open;
            panel.dataset.expanded = String(expanded);
            if (expanded) controls.forEach((other) => { if (other.panel !== panel) other.setExpanded(false); });
        });

        return { panel, setExpanded };
    });
    const countdowns = Array.from(document.querySelectorAll('[data-lavaggio-countdown]'));
    if (!countdowns.length) return;

    let refreshing = false;
    const pad = (value) => String(value).padStart(2, '0');
    const tick = () => {
        let active = false;
        countdowns.forEach((countdown) => {
            if (countdown.hidden) return;
            const end = Date.parse(countdown.dataset.endsAt);
            if (!Number.isFinite(end)) {
                countdown.hidden = true;
                return;
            }
            const total = Math.max(0, Math.ceil((end - Date.now()) / 1000));
            if (total === 0) {
                countdown.hidden = true;
                if (countdown.hasAttribute('data-refresh-on-expiry') && !refreshing) {
                    refreshing = true;
                    window.location.reload();
                }
                return;
            }
            const days = Math.floor(total / 86400);
            const hours = Math.floor(total % 86400 / 3600);
            const minutes = Math.floor(total % 3600 / 60);
            const seconds = total % 60;
            Object.entries({ days, hours, minutes, seconds }).forEach(([part, remaining]) => {
                const value = countdown.querySelector(`[data-countdown-part="${part}"]`);
                if (value) value.textContent = pad(remaining);
            });
            active = true;
        });
        if (!active && timer) window.clearInterval(timer);
    };
    let timer = null;
    tick();
    if (!refreshing && countdowns.some((countdown) => !countdown.hidden)) {
        timer = window.setInterval(tick, 1000);
    }
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) tick();
    });
})();
