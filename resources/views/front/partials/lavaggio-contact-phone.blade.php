@php
    $lavaggioPhone = trim((string) ($storeSettings['footer']['phone'] ?? ''));
    $lavaggioHours = trim((string) ($storeSettings['footer']['hours'] ?? ''));
    $lavaggioPhoneHref = preg_replace('/[^0-9+]/', '', $lavaggioPhone);
@endphp
<span class="lavaggio-contact-phone-inline">
    <a href="tel:{{ $lavaggioPhoneHref }}" @class([$lavaggioPhoneClass ?? ''])>{{ $lavaggioPhone }}</a>
    @if ($lavaggioHours !== '')
        <span class="lavaggio-contact-hours">({{ $lavaggioHours }})</span>
    @endif
</span>
