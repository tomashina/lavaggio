(() => {
    const imageSelector = 'img[data-product-card-image]';

    const frameFor = (image) => image instanceof HTMLImageElement && image.matches(imageSelector)
        ? image.closest('[data-product-card-image-frame]')
        : null;

    const reveal = (image) => {
        const frame = frameFor(image);
        if (frame) {
            frame.dataset.imageState = image.naturalWidth > 0 ? 'loaded' : 'error';
        }
    };

    const prepare = (container = document) => {
        container.querySelectorAll(imageSelector).forEach((image) => {
            const frame = frameFor(image);
            if (!frame) {
                return;
            }
            // Cached images stay visible; JS-disabled pages also keep normal images.
            if (image.complete) {
                reveal(image);
            } else {
                frame.dataset.imageState = 'loading';
            }
        });
    };

    // Capture also covers images appended by pagination or cloned by carousels.
    document.addEventListener('load', (event) => {
        const image = event.target;
        if (!frameFor(image)) {
            return;
        }
        if (typeof image.decode === 'function') {
            image.decode().catch(() => {}).then(() => reveal(image));
        } else {
            reveal(image);
        }
    }, true);
    document.addEventListener('error', (event) => reveal(event.target), true);
    document.addEventListener('catalog:items-appended', (event) => {
        prepare(event.detail?.container || document);
    });

    // Hidden secondary originals should not compete with visible product photos,
    // particularly on touch screens where the hover image is never displayed.
    const loadHoverImage = (event) => {
        if (event.type === 'pointerover' && !window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
            return;
        }
        const card = event.target.closest?.('[data-product-card]');
        const image = card?.querySelector('img[data-product-card-hover-image][data-hover-src]');
        if (!image || image.dataset.hoverLoading === '1') {
            return;
        }
        image.dataset.hoverLoading = '1';
        const frame = image.closest('[data-product-card-image-frame]');
        const ready = () => {
            if (image.naturalWidth > 0) {
                frame.dataset.hoverImageReady = '1';
            }
        };
        image.addEventListener('load', ready, { once: true });
        // Set srcset before src so the browser chooses only one candidate.
        if (image.dataset.hoverSrcset) {
            image.srcset = image.dataset.hoverSrcset;
        }
        image.src = image.dataset.hoverSrc;
        image.removeAttribute('data-hover-src');
        image.removeAttribute('data-hover-srcset');
        if (image.complete) {
            ready();
        }
    };
    document.addEventListener('pointerover', loadHoverImage);
    document.addEventListener('focusin', loadHoverImage);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => prepare(), { once: true });
    } else {
        prepare();
    }
})();
