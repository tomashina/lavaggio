(() => {
    const contentSelector = '.content-richtext, .product-detail-description-content, .catalog-description, .prose, [data-richtext-content]';
    const tableSelector = contentSelector.split(',').map((selector) => `${selector.trim()} table`).join(', ');
    const states = new Map();
    const initialized = new WeakSet();

    const update = (state) => {
        const overflowing = state.wrapper.clientWidth > 0 && state.wrapper.scrollWidth > state.wrapper.clientWidth + 1;
        for (const [attribute, value] of Object.entries({ tabindex: '0', role: 'region', 'aria-label': state.label })) {
            if (state.original[attribute] !== null || (attribute === 'aria-label' && state.wrapper.hasAttribute('aria-labelledby'))) {
                continue;
            }
            if (overflowing) {
                state.wrapper.setAttribute(attribute, value);
            } else {
                state.wrapper.removeAttribute(attribute);
            }
        }
    };

    const refresh = () => {
        for (const [wrapper, state] of states) {
            if (!wrapper.isConnected) {
                observer?.unobserve(wrapper);
                observer?.unobserve(state.table);
                states.delete(wrapper);
                continue;
            }
            update(state);
        }
    };

    const observer = typeof ResizeObserver === 'function' ? new ResizeObserver(refresh) : null;

    const enhance = (table) => {
        if (initialized.has(table) || !table.closest(contentSelector) || table.closest('[data-richtext-table-exclude]')) {
            return;
        }
        initialized.add(table);

        let wrapper = table.parentElement;
        const content = table.closest(contentSelector);
        const existingScroll = wrapper && wrapper !== content && (
            wrapper.matches('.lm-richtext-table-scroll, .table-responsive, .table-scroll, .overflow-x-auto, [data-richtext-table-scroll]')
            || (wrapper.children.length === 1 && /^(auto|scroll)$/.test(window.getComputedStyle(wrapper).overflowX))
        );
        if (!existingScroll) {
            wrapper = document.createElement('div');
            table.before(wrapper);
            wrapper.append(table);
        }
        wrapper.classList.add('lm-richtext-table-scroll');
        wrapper.setAttribute('data-richtext-table-scroll', '');

        const caption = table.caption?.textContent.replace(/\s+/g, ' ').trim();
        const state = {
            table, wrapper,
            label: caption ? `Tablica: ${caption}` : 'Tablica sadržaja. Pomaknite vodoravno za sve stupce.',
            original: Object.fromEntries(['tabindex', 'role', 'aria-label'].map((attribute) => [attribute, wrapper.getAttribute(attribute)])),
        };
        states.set(wrapper, state);
        observer?.observe(wrapper);
        observer?.observe(table);
        update(state);
    };

    const mutations = typeof MutationObserver === 'function' ? new MutationObserver((records) => {
        for (const record of records) {
            for (const node of record.addedNodes) {
                if (node.nodeType !== 1) {
                    continue;
                }
                if (node.matches('table')) {
                    enhance(node);
                }
                node.querySelectorAll('table').forEach(enhance);
            }
        }
        refresh();
    }) : null;
    let watching = false;

    const init = () => {
        if (!document.body.classList.contains('libermedia-storefront')) {
            return;
        }
        document.querySelectorAll(tableSelector).forEach(enhance);
        if (!watching && mutations) {
            mutations.observe(document.body, { childList: true, subtree: true });
            watching = true;
        }
        refresh();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
    document.addEventListener('livewire:navigated', init);
    window.addEventListener('pageshow', init);
    if (!observer) {
        window.addEventListener('resize', refresh, { passive: true });
    }
})();
