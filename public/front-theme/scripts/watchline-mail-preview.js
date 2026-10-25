(() => {
    const preview = document.querySelector('[data-mail-preview]');
    if (!preview) return;

    const select = preview.querySelector('[data-mail-sample]');
    const frame = preview.querySelector('[data-mail-frame]');
    const stage = preview.querySelector('[data-mail-stage]');
    const description = preview.querySelector('[data-mail-description]');
    const open = preview.querySelector('[data-mail-open]');

    select.addEventListener('change', () => {
        const sample = select.selectedOptions[0];
        frame.src = sample.dataset.url;
        frame.title = sample.textContent;
        description.textContent = sample.dataset.description;
        open.href = sample.dataset.url;
        const url = new URL(window.location.href);
        url.searchParams.set('sample', sample.value);
        window.history.replaceState(null, '', url);
    });

    preview.querySelectorAll('[data-mail-width]').forEach((button) => {
        button.addEventListener('click', () => {
            stage.classList.toggle('is-mobile', button.dataset.mailWidth === 'mobile');
            preview.querySelectorAll('[data-mail-width]').forEach((option) => {
                option.setAttribute('aria-pressed', String(option === button));
            });
        });
    });
})();
