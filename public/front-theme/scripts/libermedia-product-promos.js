(() => {
    const init = () => document.querySelectorAll('[data-product-promo-slot]').forEach(slot => {
        const templates = Array.from(slot.closest('.herrera-product-carousel-widget')?.querySelectorAll('[data-catalog-promo-template]') || []);
        if (!templates.length || slot.dataset.promoReady) return;
        slot.dataset.promoReady = '1';
        let index = Math.floor(Math.random() * templates.length);
        try {
            const saved = window.sessionStorage.getItem('libermedia.product-promo.next');
            if (saved !== null && Number.isInteger(Number(saved))) index = Math.abs(Number(saved)) % templates.length;
            window.sessionStorage.setItem('libermedia.product-promo.next', String((index + 1) % templates.length));
        } catch (_) { /* The server-rendered tile remains available without storage. */ }
        const card = templates[index].content.firstElementChild?.cloneNode(true);
        if (card) slot.replaceChildren(card);
    });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();
