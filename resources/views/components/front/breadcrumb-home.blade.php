@if (config('storefront.home') === 'lavaggio')
    <x-fa-icon name="house" style="light" :inline="true" class="lavaggio-breadcrumb-home-icon" />
    <span class="sr-only">{{ __('ui.front.desktop.footer.home') }}</span>
@else
    {{ $slot->isEmpty() ? __('ui.front.desktop.footer.home') : $slot }}
@endif
