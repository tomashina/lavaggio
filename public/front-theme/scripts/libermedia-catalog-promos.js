(() => {
    const gridSelector = '[data-catalog-grid][data-libermedia-catalog-promos]';
    const states = new WeakMap();

    const initGrid = grid => {
        if (!(grid instanceof HTMLElement) || states.has(grid)) return;
        const scope = grid.closest('.catalog-products-main');
        const templates = Array.from(scope?.querySelectorAll('[data-catalog-promo-template]') || []);
        if (!templates.length) return;
        const storageKey = 'libermedia.catalog-promo.start:' + grid.dataset.catalogPromoContext;
        let firstType = Math.floor(Math.random() * templates.length);
        try {
            const saved = window.sessionStorage.getItem(storageKey);
            const parsed = saved === null ? -1 : Number(saved);
            if (Number.isInteger(parsed) && parsed >= 0 && parsed < templates.length) firstType = parsed;
            else window.sessionStorage.setItem(storageKey, String(firstType));
        } catch (_) {
            // The tiles still work if the browser blocks session storage.
        }
        let pendingFrame = 0;

        const render = () => {
            pendingFrame = 0;
            if (!grid.isConnected) return;
            const columns = window.getComputedStyle(grid).gridTemplateColumns.trim();
            const columnCount = columns && columns !== 'none' ? columns.split(/\s+/).length : 0;
            const products = Array.from(grid.children).filter(child => child.hasAttribute('data-product-card'));
            const promos = Array.from(grid.children).filter(child => child.hasAttribute('data-catalog-promo-card'));
            const missing = columnCount === 5 && products.length % 5 === 4 ? 1 : 0;
            const page = Math.max(1, Number(grid.dataset.catalogPromoPage) || 1);
            const perPage = Math.max(1, Number(grid.dataset.catalogPromoPerPage) || products.length);
            const sequenceOffset = (page - 1) * (perPage % 5 === 4 ? 1 : 0);
            grid.dataset.catalogPromoColumns = String(columnCount);

            const lastChildren = Array.from(grid.children).slice(-missing);
            if (promos.length === missing && (missing === 0 || lastChildren.every((child, index) =>
                child.dataset.catalogPromoCard === templates[(firstType + sequenceOffset + index) % templates.length].dataset.catalogPromoTemplate
            ))) return;
            promos.forEach(promo => promo.remove());
            if (!missing) return;
            const fragment = document.createDocumentFragment();
            for (let index = 0; index < missing; index++) {
                const template = templates[(firstType + sequenceOffset + index) % templates.length];
                const card = template.content.firstElementChild?.cloneNode(true);
                if (card) fragment.append(card);
            }
            grid.append(fragment);
        };
        const schedule = () => {
            if (!pendingFrame) pendingFrame = window.requestAnimationFrame(render);
        };
        const mutations = new MutationObserver(schedule);
        mutations.observe(grid, { childList: true, attributes: true, attributeFilter: ['class', 'style', 'data-catalog-promo-page'] });
        const resize = typeof window.ResizeObserver === 'function' ? new ResizeObserver(schedule) : null;
        resize?.observe(grid);
        const dispose = () => {
            mutations.disconnect();
            resize?.disconnect();
            window.removeEventListener('resize', schedule);
            if (pendingFrame) window.cancelAnimationFrame(pendingFrame);
            states.delete(grid);
        };
        states.set(grid, { schedule, dispose });
        window.addEventListener('resize', schedule, { passive: true });
        render();
    };
    const visitGrids = (node, callback) => {
        if (!(node instanceof Element)) return;
        if (node.matches(gridSelector)) callback(node);
        node.querySelectorAll(gridSelector).forEach(callback);
    };
    const init = () => {
        document.querySelectorAll(gridSelector).forEach(initGrid);
        document.addEventListener('catalog:items-appended', event => {
            const grid = event.detail?.container;
            if (grid?.matches(gridSelector)) { initGrid(grid); states.get(grid)?.schedule(); }
        });
        // Reinitialize after a filter or pagination module replaces the catalog markup.
        new MutationObserver(records => records.forEach(record => {
            record.removedNodes.forEach(node => visitGrids(node, grid => states.get(grid)?.dispose()));
            record.addedNodes.forEach(node => visitGrids(node, initGrid));
        })).observe(document.querySelector('main') || document.body, { childList: true, subtree: true });
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();
