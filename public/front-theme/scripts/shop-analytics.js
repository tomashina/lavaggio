(function () {
    'use strict';

    function trackGoogle(name, params) {
        if (typeof window.gtag !== 'function' || window.canTrackAnalytics?.() !== true) return false;
        window.gtag('event', name, params);
        return true;
    }

    function trackMeta(name, params) {
        return window.ShopMetaPixel?.trackFromGa4(name, params) === true;
    }

    function track(name, params) {
        const google = trackGoogle(name, params);
        const meta = trackMeta(name, params);
        return google || meta;
    }

    function wasSent(key) {
        try { return window.sessionStorage.getItem(key) === '1'; }
        catch { return false; }
    }

    function remember(key) {
        try { window.sessionStorage.setItem(key, '1'); }
        catch { /* GA4 also deduplicates purchases by transaction_id. */ }
    }

    // On a landing page, the consent dialog can resolve after DOMContentLoaded.
    // Hold only this page's impression or receipt until its consent is known.
    function trackPage(name, params, onceKey, metaOnly = false) {
        const sent = new Set();
        function send(channel, tracker) {
            if (sent.has(channel)) return;
            const key = onceKey && 'shop-ga4-once:' + channel + ':' + onceKey;
            if (key && wasSent(key)) {
                sent.add(channel);
                return;
            }
            if (tracker(name, params)) {
                sent.add(channel);
                if (key) remember(key);
            }
        }
        function sync() {
            if (!metaOnly) send('ga4', trackGoogle);
            send('meta', trackMeta);
        }
        document.addEventListener('shop:consent', sync);
        sync();
    }

    function trackList(params) {
        trackPage('view_item_list', params);
        document.addEventListener('click', function (event) {
            const link = event.target.closest?.('a');
            const card = link?.closest('[data-catalog-grid] [data-product-card]');
            if (!card || link.pathname !== card.querySelector('a')?.pathname) return;
            const item = params.items.find(item => item.item_id === card.dataset.ga4ItemId);
            if (item) track('select_item', { ...params, items: [item] });
        });
    }

    function bindCheckout() {
        const form = document.querySelector('[data-ga4-checkout-form]');
        if (!form) return;
        const cart = () => JSON.parse(form.dataset.ga4Cart || 'null');
        const initial = cart();
        if (!initial?.items?.length) return;
        trackPage('begin_checkout', initial);

        const lastSelection = new Map();
        function selection(input) {
            if (!input?.checked || !input.value) return;
            const shipping = input.name === 'shipping_method_code';
            if (!shipping && input.name !== 'payment_method_code') return;
            const eventName = shipping ? 'add_shipping_info' : 'add_payment_info';
            const params = { ...cart(), [shipping ? 'shipping_tier' : 'payment_type']: input.value };
            const signature = JSON.stringify(params);
            if (lastSelection.get(eventName) !== signature && track(eventName, params)) {
                lastSelection.set(eventName, signature);
            }
        }
        // Shipping and payment radios are replaced after an address or coupon change.
        form.addEventListener('change', event => selection(event.target));
        form.addEventListener('checkout:accepted', function () {
            selection(form.querySelector('input[name="shipping_method_code"]:checked'));
            selection(form.querySelector('input[name="payment_method_code"]:checked'));
        });
    }

    document.addEventListener('cart:updated', function (event) {
        const change = event.detail?.ecommerce;
        if (change) track(change.name, change.params);
    });
    window.ShopAnalytics = {
        track,
        trackPage,
        trackList,
        trackOnce: (key, name, params) => trackPage(name, params, key),
        trackMetaOnce: (key, name, params) => trackPage(name, params, key, true),
    };
    document.addEventListener('DOMContentLoaded', bindCheckout);
})();
