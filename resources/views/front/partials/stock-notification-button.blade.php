@include('front.partials.stock-notification-assets')
@php
    $notificationParameters = !empty($notifyBundleId)
        ? ['bundle_id' => (int) $notifyBundleId]
        : ['product_id' => (int) $notifyProductId];
    if (!empty($notifyOptionId)) {
        $notificationParameters['product_option_value_id'] = (int) $notifyOptionId;
    }
@endphp
<a
    href="{{ route('stock-notifications.create', $notificationParameters) }}"
    class="stock-notify-button {{ $notifyClass ?? '' }}"
    data-stock-notify-trigger
    @if (!empty($notifyBundleId)) data-bundle-id="{{ (int) $notifyBundleId }}" @else data-product-id="{{ (int) $notifyProductId }}" @endif
    data-product-name="{{ $notifyName ?? '' }}"
    @if (!empty($notifyOptionId)) data-product-option-value-id="{{ (int) $notifyOptionId }}" @endif
    @if (!empty($notifyHidden)) hidden @endif
>
    <x-fa-icon name="bell" class="stock-notify-button-icon" />
    <span>Obavijesti me<span class="stock-notify-button-desktop-text"> o dostupnosti</span></span>
</a>
@once('stock-notification-modal')
    @push('stock-notification-modals')
        @include('front.partials.stock-notification-modal')
    @endpush
@endonce
