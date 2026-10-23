(() => {
    const panel = document.querySelector('[data-checkout-coupon]');
    if (!panel) return;
    const apply = document.getElementById('checkout-coupon-apply');
    const remove = document.getElementById('checkout-coupon-remove');
    const input = panel.querySelector('[name=coupon_code]');
    const status = panel.querySelector('[data-checkout-coupon-status]');
    const removeButton = panel.querySelector('[data-checkout-coupon-remove]');
    let pending = false;
    [apply, remove].forEach(form => form?.addEventListener('submit', async event => {
        event.preventDefault();
        if (pending) return;
        pending = true;
        panel.querySelectorAll('button').forEach(button => { button.disabled = true; button.setAttribute('aria-busy', 'true'); });
        try {
            const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            const data = await response.json();
            if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'Kod nije moguće primijeniti.');
            input.value = data.coupon_code || '';
            input.removeAttribute('aria-invalid');
            removeButton.hidden = !data.coupon_code;
            status.textContent = data.message;
            status.hidden = false;
            status.classList.remove('is-error');
            window.dispatchEvent(new CustomEvent('checkout-discount:updated'));
        } catch (error) {
            status.textContent = error instanceof SyntaxError ? 'Zahtjev nije dovršen. Pokušajte ponovno.' : (error.message === 'Failed to fetch' ? 'Provjerite internetsku vezu i pokušajte ponovno.' : error.message);
            status.hidden = false;
            status.classList.add('is-error');
            if (form === apply) { input.setAttribute('aria-invalid', 'true'); input.focus(); }
        } finally {
            pending = false;
            panel.querySelectorAll('button').forEach(button => { button.disabled = false; button.removeAttribute('aria-busy'); });
        }
    }));
})();
