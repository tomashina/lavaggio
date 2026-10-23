document.querySelectorAll('[data-bundle-cart-form]').forEach((form) => {
    const input = form.querySelector('[data-bundle-quantity]');
    const decrease = form.querySelector('[data-bundle-quantity-dec]');
    const increase = form.querySelector('[data-bundle-quantity-inc]');
    const submit = form.querySelector('button[type="submit"]');
    if (!input || !decrease || !increase || !submit) return;

    const defaultLabel = submit.getAttribute('aria-label');
    const sync = () => {
        const quantity = Number(input.value);
        const pending = form.dataset.submitting === 'true';
        const valid = input.value !== '' && Number.isInteger(quantity) && quantity >= 1 && quantity <= 99;
        input.setCustomValidity(valid ? '' : 'Unesite broj kompleta od 1 do 99.');
        decrease.disabled = pending || quantity <= 1;
        increase.disabled = pending || quantity >= 99;
        input.readOnly = pending;
    };
    const changeQuantity = (difference) => {
        if (form.dataset.submitting === 'true') return;
        const quantity = Number(input.value);
        input.value = String(Math.max(1, Math.min(99, (Number.isInteger(quantity) ? quantity : 1) + difference)));
        sync();
    };
    decrease.addEventListener('click', () => changeQuantity(-1));
    increase.addEventListener('click', () => changeQuantity(1));
    input.addEventListener('input', sync);
    sync();

    form.addEventListener('submit', (event) => {
        if (form.dataset.submitting === 'true') {
            event.preventDefault();
            return;
        }
        sync();
        if (!form.reportValidity()) {
            event.preventDefault();
            return;
        }
        form.dataset.submitting = 'true';
        submit.disabled = true;
        submit.classList.add('is-pending');
        submit.setAttribute('aria-busy', 'true');
        submit.setAttribute('aria-label', 'Dodajemo komplet…');
        submit.querySelector('span').textContent = 'Dodajemo komplet…';
        sync();
    });

    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        form.dataset.submitting = 'false';
        submit.disabled = false;
        submit.classList.remove('is-pending');
        submit.removeAttribute('aria-busy');
        submit.setAttribute('aria-label', defaultLabel);
        submit.querySelector('span').textContent = 'Dodaj komplet u košaricu';
        sync();
    });
});
