(() => {
    const root = document.querySelector('[data-watchline-gift-guide]');
    if (!root) return;
    root.addEventListener('change', (event) => {
        if (event.target.matches('[data-gift-auto-submit]')) {
            event.target.form?.requestSubmit();
        }
    });
})();
