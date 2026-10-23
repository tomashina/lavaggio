(() => {
    const dialog = document.querySelector('[data-stock-notify-dialog]');
    let returnFocus = null;
    let previousOverflow = '';
    const activeRequests = new WeakMap();
    const forms = Array.from(document.querySelectorAll('[data-stock-notify-form]'));

    const clearFeedback = (form) => {
        form.querySelectorAll('[data-stock-notify-error]').forEach((node) => { node.hidden = true; node.textContent = ''; });
        form.querySelectorAll('[aria-invalid]').forEach((node) => node.removeAttribute('aria-invalid'));
        const status = form.querySelector('[data-stock-notify-status]');
        status.hidden = true;
        status.textContent = '';
        status.classList.remove('is-success');
        status.setAttribute('role', 'status');
    };
    const closeDialog = () => {
        if (dialog && dialog.open) dialog.close();
    };

    if (dialog && typeof dialog.showModal === 'function') {
        document.addEventListener('click', (event) => {
            const trigger = event.target.closest('[data-stock-notify-trigger]');
            if (!trigger || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            const form = dialog.querySelector('form');
            activeRequests.get(form)?.abort();
            activeRequests.delete(form);
            form.dataset.submitting = '0';
            form.reset();
            clearFeedback(form);
            const productForm = trigger.closest('[data-product-detail-form]') || (trigger.closest('[data-product-floating-cart]') ? document.getElementById(trigger.closest('[data-product-floating-cart]').dataset.cartFormId) : null);
            const selectedRadio = productForm?.querySelector('input[name="product_option_value_id"]:checked');
            const selectedSelect = productForm?.querySelector('select[name="product_option_value_id"]');
            const option = selectedRadio || (selectedSelect && !selectedSelect.disabled && selectedSelect.value ? selectedSelect.options[selectedSelect.selectedIndex] : null);
            const values = {
                product_id: trigger.dataset.productId || '',
                bundle_id: trigger.dataset.bundleId || '',
                product_option_value_id: option?.value || trigger.dataset.productOptionValueId || '',
            };
            Object.entries(values).forEach(([name, value]) => {
                const input = form.elements.namedItem(name);
                input.value = value;
                input.disabled = !value;
            });
            const optionName = option ? (option.dataset.sizeLabel || option.textContent || '').trim() : '';
            dialog.querySelector('[data-stock-notify-product]').textContent = (trigger.dataset.productName || '') + (optionName ? ' · ' + optionName : '');
            const submit = form.querySelector('[type="submit"]');
            submit.disabled = false;
            submit.removeAttribute('aria-busy');
            form.querySelector('[data-stock-notify-submit-label]').textContent = 'Obavijesti me o dostupnosti';
            returnFocus = trigger;
            previousOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            dialog.showModal();
            form.elements.namedItem('email').focus({ preventScroll: true });
        });
        dialog.querySelector('[data-stock-notify-close]').addEventListener('click', closeDialog);
        dialog.addEventListener('click', (event) => {
            if (event.target !== dialog) return;
            const bounds = dialog.getBoundingClientRect();
            if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) closeDialog();
        });
        dialog.addEventListener('close', () => {
            const form = dialog.querySelector('form');
            activeRequests.get(form)?.abort();
            activeRequests.delete(form);
            document.body.style.overflow = previousOverflow;
            returnFocus?.focus({ preventScroll: true });
        });
        dialog.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') { event.preventDefault(); closeDialog(); return; }
            if (event.key !== 'Tab') return;
            const focusable = Array.from(dialog.querySelectorAll('button:not([disabled]), input:not([type="hidden"]):not([disabled]):not([tabindex="-1"]), a[href]')).filter((node) => node.getClientRects().length);
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
        });
    }

    forms.forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (form.dataset.submitting === '1') return;
            clearFeedback(form);
            const email = form.elements.namedItem('email');
            const consent = form.elements.namedItem('consent');
            email.value = email.value.trim();
            let invalid = null;
            [[email, !email.value || !email.validity.valid, !email.value ? 'Upišite svoju e-mail adresu.' : 'Upišite ispravnu e-mail adresu.'],
                [consent, !consent.checked, 'Za ovu obavijest potrebno je označiti privolu.']].forEach(([field, hasError, message]) => {
                if (!hasError) return;
                field.setAttribute('aria-invalid', 'true');
                const error = form.querySelector('[data-stock-notify-error="' + field.name + '"]');
                error.textContent = message;
                error.hidden = false;
                invalid ||= field;
            });
            if (invalid) { invalid.focus(); return; }
            const data = new FormData(form);
            const submit = form.querySelector('[type="submit"]');
            const label = form.querySelector('[data-stock-notify-submit-label]');
            const status = form.querySelector('[data-stock-notify-status]');
            const controller = new AbortController();
            activeRequests.set(form, controller);
            const timeout = window.setTimeout(() => controller.abort(), 20000);
            form.dataset.submitting = '1';
            submit.disabled = true;
            submit.setAttribute('aria-busy', 'true');
            label.textContent = 'Spremamo prijavu…';
            let successful = false;
            try {
                const response = await fetch(form.action, { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: data, signal: controller.signal });
                const payload = await response.json().catch(() => ({}));
                if (activeRequests.get(form) !== controller) return;
                if (!response.ok) {
                    let firstInvalid = null;
                    if (response.status === 422 && payload.errors) {
                        Object.entries(payload.errors).forEach(([name, messages]) => {
                            const error = form.querySelector('[data-stock-notify-error="' + name + '"]');
                            const field = form.elements.namedItem(name);
                            if (error) { error.textContent = Array.isArray(messages) ? messages[0] : String(messages); error.hidden = false; }
                            if (field && field.type !== 'hidden') { field.setAttribute('aria-invalid', 'true'); firstInvalid ||= field; }
                        });
                    }
                    status.textContent = response.status === 419 ? 'Prijava je istekla. Osvježite stranicu i pokušajte ponovno.' : response.status === 429 ? 'Previše pokušaja. Pričekajte trenutak pa pokušajte ponovno.' : payload.message || 'Prijavu nije moguće spremiti. Provjerite podatke i pokušajte ponovno.';
                    status.hidden = false;
                    status.setAttribute('role', 'alert');
                    (firstInvalid || status).focus({ preventScroll: true });
                    return;
                }
                successful = true;
                status.textContent = payload.message || 'Prijava je spremljena. Javit ćemo vam e-mailom kada ponovno bude dostupno.';
                status.classList.add('is-success');
                status.hidden = false;
                status.focus({ preventScroll: true });
                label.textContent = 'Prijava je spremljena';
            } catch (error) {
                if (activeRequests.get(form) !== controller) return;
                status.textContent = 'Prijavu nije moguće spremiti. Provjerite internetsku vezu i pokušajte ponovno.';
                status.hidden = false;
                status.setAttribute('role', 'alert');
                status.focus({ preventScroll: true });
            } finally {
                window.clearTimeout(timeout);
                if (activeRequests.get(form) !== controller) return;
                activeRequests.delete(form);
                form.dataset.submitting = '0';
                submit.removeAttribute('aria-busy');
                submit.disabled = successful;
                if (!successful) label.textContent = 'Obavijesti me o dostupnosti';
            }
        });
    });
})();
