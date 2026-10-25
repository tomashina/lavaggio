@once
    @push('scripts')
        @php
            $splidePreviousLabel = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.previous')));
            $splideNextLabel = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.next')));
            $splideArrowLabels = ['prev' => $splidePreviousLabel, 'next' => $splideNextLabel, 'first' => $splideNextLabel, 'last' => $splidePreviousLabel];
        @endphp
        <script defer data-storefront-splide src="{{ asset('vendor/splide/splide.min.js') }}?v={{ filemtime(public_path('vendor/splide/splide.min.js')) }}"></script>
        <script>
            (function () {
                const localizeArrows = function () {
                    if (typeof window.Splide === 'function') {
                        // A tap on iOS often includes a few pixels of finger movement.
                        // Only a deliberate swipe should suppress a product link click.
                        window.Splide.defaults = {
                            i18n: @json($splideArrowLabels),
                            dragMinThreshold: { mouse: 3, touch: 24 },
                            noDrag: 'button, button *, input, textarea, select, [data-no-drag], [data-no-drag] *',
                        };
                    }
                };
                const loader = document.querySelector('[data-storefront-splide]');
                if (loader) {
                    loader.addEventListener('load', localizeArrows, { once: true });
                }
                localizeArrows();
            })();
        </script>
    @endpush
@endonce
