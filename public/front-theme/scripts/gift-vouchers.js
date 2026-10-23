(() => {
    const purchaseForm = document.querySelector('[data-gift-purchase-form]');
    if (purchaseForm) {
        const amount = purchaseForm.querySelector('[data-gift-amount]');
        const paymentOptions = purchaseForm.querySelector('[data-gift-payment-options]');
        const submit = purchaseForm.querySelector('[data-gift-submit]');
        const error = purchaseForm.querySelector('[data-gift-payment-error]');
        let abortController;

        const stickyPreview = document.querySelector('[data-gift-sticky-preview]');
        const header = document.querySelector('.site-main-header');
        if (stickyPreview) {
            let pendingFrame = false;
            const updateStickyPreview = () => {
                pendingFrame = false;
                const offset = Math.ceil(Math.max(0, header?.getBoundingClientRect().bottom || 0) + 16);
                const fits = stickyPreview.getBoundingClientRect().height <= window.innerHeight - offset - 16;
                stickyPreview.style.setProperty('--gift-voucher-sticky-top', offset + 'px');
                stickyPreview.dataset.stickyDisabled = String(!fits);
            };
            const scheduleStickyPreview = () => {
                if (!pendingFrame) { pendingFrame = true; window.requestAnimationFrame(updateStickyPreview); }
            };
            window.addEventListener('scroll', scheduleStickyPreview, { passive: true });
            window.addEventListener('resize', scheduleStickyPreview, { passive: true });
            if (typeof window.ResizeObserver === 'function') {
                const observer = new ResizeObserver(scheduleStickyPreview);
                observer.observe(stickyPreview);
                if (header) observer.observe(header);
            }
            if (header && typeof window.MutationObserver === 'function') {
                new MutationObserver(scheduleStickyPreview).observe(header, { attributes: true, attributeFilter: ['class', 'style'] });
            }
            document.fonts?.ready.then(scheduleStickyPreview);
            updateStickyPreview();
        }

        const showTotal = () => {
            const selected = paymentOptions.querySelector('input:checked');
            const hasFee = selected && Number(selected.dataset.giftMethodFee) > 0;
            purchaseForm.querySelector('[data-gift-payment-fee-row]').hidden = !hasFee;
            purchaseForm.querySelector('[data-gift-payment-fee-value]').textContent = hasFee ? selected.dataset.giftMethodFeeFormatted : '';
            purchaseForm.querySelector('[data-gift-grand-total]').textContent = selected?.dataset.giftMethodTotalFormatted || '—';
        };
        const updatePreview = () => {
            document.querySelector('[data-gift-preview-amount]').textContent = amount.value;
            document.querySelector('[data-gift-preview-recipient]').textContent = purchaseForm.querySelector('[data-gift-recipient]').value.trim() || 'nekoga posebnog';
            document.querySelector('[data-gift-preview-sender]').textContent = purchaseForm.querySelector('[data-gift-sender]').value.trim() || 'vas, od srca';
            document.querySelector('[data-gift-preview-message]').textContent = purchaseForm.querySelector('[data-gift-message]').value.trim() || purchaseForm.dataset.giftDefaultMessage || 'Poklonite trenutke pune igre i veselja.';
            purchaseForm.querySelectorAll('[data-gift-amount-preset]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.giftAmountPreset === amount.value)));
        };
        const updatePaymentOptions = async () => {
            updatePreview();
            abortController?.abort();
            abortController = new AbortController();
            const signal = abortController.signal;
            const current = paymentOptions.querySelector('input:checked')?.value;
            submit.disabled = true;
            paymentOptions.setAttribute('aria-busy', 'true');
            error.hidden = true;
            try {
                const url = new URL(purchaseForm.dataset.paymentOptionsUrl, window.location.origin);
                url.searchParams.set('amount', amount.value);
                const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', signal });
                if (!response.ok) throw new Error('payment-options');
                const data = await response.json();
                if (signal.aborted) return;
                const methods = Array.isArray(data.payment_methods) ? data.payment_methods : [];
                paymentOptions.replaceChildren();
                const selectedCode = methods.some(method => method.code === current) ? current : methods[0]?.code;
                methods.forEach(method => {
                    const label = document.createElement('label');
                    label.className = 'gift-voucher-payment-option';
                    const input = document.createElement('input');
                    input.type = 'radio'; input.name = 'payment_method_code'; input.value = method.code; input.required = true; input.checked = method.code === selectedCode;
                    input.dataset.giftMethodFee = String(method.payment_fee_total);
                    input.dataset.giftMethodFeeFormatted = method.payment_fee_total_formatted;
                    input.dataset.giftMethodTotalFormatted = method.grand_total_formatted;
                    const copy = document.createElement('span'); copy.className = 'gift-voucher-payment-copy';
                    const name = document.createElement('strong'); name.textContent = method.name; copy.append(name);
                    if (method.description) { const description = document.createElement('small'); description.textContent = method.description; copy.append(description); }
                    label.append(input, copy); paymentOptions.append(label);
                });
                purchaseForm.querySelector('[data-gift-total-amount]').textContent = data.amount_formatted;
                submit.disabled = methods.length === 0;
                if (!methods.length) { error.textContent = 'Kupnja poklon bona trenutačno nije dostupna. Pokušajte ponovno kasnije.'; error.hidden = false; }
                showTotal();
            } catch (exception) {
                if (exception.name === 'AbortError') return;
                error.textContent = 'Ne možemo provjeriti iznos i način plaćanja. Ponovno odaberite iznos ili osvježite stranicu.';
                error.hidden = false;
            } finally {
                if (!signal.aborted) paymentOptions.removeAttribute('aria-busy');
            }
        };
        purchaseForm.querySelectorAll('[data-gift-amount-preset]').forEach(button => button.addEventListener('click', () => { amount.value = button.dataset.giftAmountPreset; updatePaymentOptions(); }));
        amount.addEventListener('change', updatePaymentOptions);
        purchaseForm.querySelectorAll('[data-gift-recipient], [data-gift-sender], [data-gift-message]').forEach(input => input.addEventListener('input', updatePreview));
        paymentOptions.addEventListener('change', showTotal);
        purchaseForm.addEventListener('submit', () => { submit.disabled = true; submit.setAttribute('aria-busy', 'true'); });
        updatePreview();
        showTotal();
    }

    document.querySelectorAll('[data-gift-voucher-redemption]').forEach(panel => {
        const applyForm = document.getElementById(panel.dataset.giftApplyForm);
        const removeForm = document.getElementById(panel.dataset.giftRemoveForm);
        const removeControl = panel.querySelector('[data-gift-voucher-remove-control]');
        const codeInput = panel.querySelector('[name=gift_voucher_code]');
        if (!applyForm || !removeForm || !codeInput) return;
        const status = panel.querySelector('[data-gift-voucher-status]');
        let pending = false;
        [applyForm, removeForm].forEach(form => form.addEventListener('submit', async event => {
            event.preventDefault();
            if (pending) return;
            pending = true;
            panel.querySelectorAll('button').forEach(button => { button.disabled = true; button.setAttribute('aria-busy', 'true'); });
            try {
                const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                const data = await response.json();
                if (!response.ok) {
                    const errors = Object.values(data.errors || {}).flat();
                    throw new Error(errors[0] || data.message || 'Kod nije moguće provjeriti. Pokušajte ponovno.');
                }
                codeInput.value = data.code || '';
                codeInput.removeAttribute('aria-invalid');
                removeControl.hidden = !data.code;
                status.textContent = data.message; status.classList.remove('is-error'); status.hidden = false;
                document.querySelectorAll('[data-cart-gift-voucher-row]').forEach(row => row.hidden = Number(data.amount) <= 0);
                document.querySelectorAll('[data-cart-gift-voucher-total]').forEach(value => value.textContent = '−' + data.amount_formatted);
                document.querySelectorAll('[data-cart-after-gift-total]').forEach(value => value.textContent = data.remaining_total_formatted);
                window.dispatchEvent(new CustomEvent('gift-voucher:updated'));
            } catch (exception) {
                status.textContent = exception.message === 'Failed to fetch' ? 'Provjerite internetsku vezu i pokušajte ponovno.' : (exception instanceof SyntaxError ? 'Zahtjev nije dovršen. Osvježite stranicu i pokušajte ponovno.' : exception.message);
                status.classList.add('is-error'); status.hidden = false;
                if (form === applyForm) { codeInput.setAttribute('aria-invalid', 'true'); codeInput.focus(); }
            } finally {
                pending = false;
                panel.querySelectorAll('button').forEach(button => { button.disabled = false; button.removeAttribute('aria-busy'); });
            }
        }));
    });
})();
