(() => {
    const init = () => {
        const catalogs = Array.from(document.querySelectorAll('[data-lavaggio-catalog]'));
        catalogs.forEach((catalog) => {
        const trigger = catalog?.querySelector('[data-lavaggio-catalog-trigger]');
        const menu = catalog?.querySelector('[data-lavaggio-catalog-menu]');
        if (!trigger || !menu || catalog.dataset.catalogReady === '1') return;
        catalog.dataset.catalogReady = '1';

        const roots = Array.from(menu.querySelectorAll('[data-lavaggio-catalog-root]'));
        const panels = Array.from(menu.querySelectorAll('[data-lavaggio-catalog-panel]'));
        const desktop = window.matchMedia('(min-width: 1280px)');
        const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)');
        let activeRoot = roots[0];
        let closeTimer;
        let openedByHover = false;

        const selectRoot = (root) => {
            activeRoot = root;
            roots.forEach((entry) => {
                const active = entry === root;
                entry.classList.toggle('is-active', active);
                entry.setAttribute('aria-expanded', String(active));
            });
            panels.forEach((panel) => { panel.hidden = panel.id !== root.dataset.panelId; });
        };
        const cancelClose = () => window.clearTimeout(closeTimer);
        const close = (restoreFocus = false) => {
            cancelClose();
            menu.hidden = true;
            openedByHover = false;
            trigger.setAttribute('aria-expanded', 'false');
            if (restoreFocus) trigger.focus();
        };
        const open = () => {
            catalogs.forEach((other) => {
                if (other === catalog) return;
                const otherMenu = other.querySelector('[data-lavaggio-catalog-menu]');
                const otherTrigger = other.querySelector('[data-lavaggio-catalog-trigger]');
                if (otherMenu) otherMenu.hidden = true;
                otherTrigger?.setAttribute('aria-expanded', 'false');
            });
            cancelClose();
            menu.hidden = false;
            openedByHover = false;
            trigger.setAttribute('aria-expanded', 'true');
        };
        const activePanelLinks = () => Array.from(
            panels.find((panel) => !panel.hidden)?.querySelectorAll('a[href]') || []
        );

        trigger.addEventListener('click', () => {
            if (menu.hidden) open();
            else if (openedByHover) openedByHover = false;
            else close();
        });
        catalog.addEventListener('pointerenter', (event) => {
            cancelClose();
            if (desktop.matches && finePointer.matches && event.pointerType !== 'touch' && menu.hidden) {
                open();
                openedByHover = true;
            }
        });
        catalog.addEventListener('pointerleave', () => {
            if (desktop.matches && finePointer.matches && !catalog.contains(document.activeElement)) {
                closeTimer = window.setTimeout(() => close(), 140);
            }
        });
        catalog.addEventListener('focusout', () => {
            window.setTimeout(() => {
                if (!catalog.contains(document.activeElement) && !catalog.matches(':hover')) close();
            }, 0);
        });
        roots.forEach((root) => {
            root.addEventListener('pointerenter', (event) => {
                if (event.pointerType !== 'touch') selectRoot(root);
            });
            root.addEventListener('focus', () => selectRoot(root));
        });
        catalog.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !menu.hidden) {
                event.preventDefault();
                close(true);
                return;
            }
            if (event.target === trigger && ['ArrowDown', 'ArrowUp'].includes(event.key)) {
                event.preventDefault();
                open();
                (event.key === 'ArrowUp' ? roots.at(-1) : roots[0])?.focus();
                return;
            }
            const rootIndex = roots.indexOf(event.target);
            if (rootIndex !== -1) {
                if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
                    event.preventDefault();
                    const index = event.key === 'Home' ? 0 : event.key === 'End' ? roots.length - 1
                        : (rootIndex + (event.key === 'ArrowUp' ? -1 : 1) + roots.length) % roots.length;
                    roots[index]?.focus();
                } else if (event.key === 'ArrowRight') {
                    event.preventDefault();
                    activePanelLinks()[0]?.focus();
                }
            } else if (menu.contains(event.target) && event.key === 'ArrowLeft') {
                event.preventDefault();
                activeRoot?.focus();
            }
        });
        document.addEventListener('pointerdown', (event) => {
            if (!catalog.contains(event.target)) close();
        });
        document.querySelectorAll('[data-mobile-menu-open]').forEach((button) => button.addEventListener('click', () => close()));
        desktop.addEventListener('change', () => close());
        window.addEventListener('pageshow', () => close());
        });
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();
