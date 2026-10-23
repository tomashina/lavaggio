@if (config('storefront.home') === 'watchline')
    @once('watchline-stock-notification-assets')
        @push('styles')
            <link rel="stylesheet" href="{{ asset('front-theme/styles/stock-notifications.css') }}?v={{ filemtime(public_path('front-theme/styles/stock-notifications.css')) }}">
        @endpush
        @push('scripts')
            <script defer src="{{ asset('front-theme/scripts/stock-notifications.js') }}?v={{ filemtime(public_path('front-theme/scripts/stock-notifications.js')) }}"></script>
        @endpush
    @endonce
@endif
