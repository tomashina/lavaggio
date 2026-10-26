@once
    @push('scripts')
        @if (config('storefront.home') === 'watchline' && request()->routeIs('home'))
        <script defer src="{{ asset('front-theme/scripts/watchline-home-carousels.js') }}?v={{ filemtime(public_path('front-theme/scripts/watchline-home-carousels.js')) }}"></script>
        @else
        <script>
            (function () {
                const init = function () {
                    if (typeof window.Splide !== 'function') {
                        return false;
                    }
                    document.querySelectorAll('[data-herrera-home-carousel]').forEach(function (el) {
                        if (el.dataset.splideReady === '1') {
                            return;
                        }
                        const cardControls = Array.from(el.querySelectorAll('a, button, textarea, input, select, iframe'))
                            .map(function (node) { return [node, node.getAttribute('tabindex')]; });
                        const options = {};
                        if (document.body.classList.contains('watchline-storefront') && el.matches('[data-continuous-card-carousel]')) {
                            options.speed = 420;
                            options.easing = 'cubic-bezier(0.25, 1, 0.5, 1)';
                        }
                        new window.Splide(el, options).mount({
                            RestoreCardGridFocus: function () {
                                return {
                                    destroy: function () {
                                        cardControls.forEach(function ([node, tabindex]) {
                                            if (tabindex === null) {
                                                node.removeAttribute('tabindex');
                                            } else {
                                                node.setAttribute('tabindex', tabindex);
                                            }
                                        });
                                    },
                                };
                            },
                        });
                        el.dataset.splideReady = '1';
                    });
                    return true;
                };
                if (init()) {
                    return;
                }
                let attempts = 0;
                const timer = window.setInterval(function () {
                    attempts += 1;
                    if (init() || attempts > 40) {
                        window.clearInterval(timer);
                    }
                }, 120);
            })();
        </script>
        @endif
    @endpush
@endonce
