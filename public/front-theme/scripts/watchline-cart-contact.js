(function () {
    const form = document.querySelector('[data-checkout-form]');
    if (!form || !form.dataset.watchlineCartContactUrl) return;
    const email = form.querySelector('[name="customer_email"]');
    const consent = form.querySelector('[name="newsletter_opt_in"]');
    const csrf = form.querySelector('[name="_token"]');
    const capture = function () {
        if (!email || !email.validity.valid || !email.value.trim()) return;
        const name = form.querySelector('[name="customer_name"]')?.value || '';
        fetch(form.dataset.watchlineCartContactUrl, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf?.value || '' },
            body: JSON.stringify({ email: email.value.trim(), name: name, consent: !!consent?.checked }),
        }).catch(function () {});
    };
    email?.addEventListener('blur', capture);
    consent?.addEventListener('change', capture);
})();
