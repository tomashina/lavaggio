(() => {
    const init = () => document.querySelectorAll('[data-blog-share]').forEach((root) => {
        const button = root.querySelector('[data-blog-copy-url]');
        const feedback = root.querySelector('[data-blog-share-feedback]');
        const fallback = root.querySelector('[data-blog-share-fallback]');
        if (!button || button.dataset.copyReady) return;
        button.dataset.copyReady = 'true';
        button.addEventListener('click', async () => {
            try {
                if (!navigator.clipboard?.writeText) throw new Error('Clipboard unavailable');
                await navigator.clipboard.writeText(button.dataset.blogCopyUrl);
                feedback.textContent = 'Poveznica je kopirana.';
            } catch {
                fallback.hidden = false;
                const input = fallback.querySelector('input');
                input.focus();
                input.select();
                feedback.textContent = 'Označena je poveznica članka. Kopirajte je za dijeljenje.';
            }
        });
    });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true});
    else init();
})();
