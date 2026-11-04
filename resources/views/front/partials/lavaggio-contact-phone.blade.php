@php
    $lavaggioPhone = trim((string) ($storeSettings['footer']['phone'] ?? ''));
    $lavaggioHours = trim((string) ($storeSettings['footer']['hours'] ?? ''));
    $lavaggioPhoneHref = preg_replace('/[^0-9+]/', '', $lavaggioPhone);
@endphp
<span class="lavaggio-contact-phone-inline">
    <a href="tel:{{ $lavaggioPhoneHref }}" @class([$lavaggioPhoneClass ?? ''])>{{ $lavaggioPhone }}</a>
    @if (($lavaggioShowHours ?? true) && $lavaggioHours !== '')
        @include('front.partials.lavaggio-contact-hours')
    @endif
</span>
