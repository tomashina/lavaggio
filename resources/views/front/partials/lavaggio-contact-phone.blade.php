@php
    $lavaggioPhone = trim((string) ($storeSettings['footer']['phone'] ?? ''));
    $lavaggioHours = trim((string) ($storeSettings['footer']['hours'] ?? ''));
    $lavaggioPhoneHref = preg_replace('/[^0-9+]/', '', $lavaggioPhone);
@endphp
<span class="lavaggio-contact-phone-inline">
    <a href="tel:{{ $lavaggioPhoneHref }}" @class([$lavaggioPhoneClass ?? ''])>{{ $lavaggioPhone }}</a>
    @if ($lavaggioHours !== '')
        <span class="lavaggio-contact-hours"><x-fa-icon name="clock" inline class="lavaggio-contact-hours-icon" /><span class="sr-only">Radno vrijeme: </span>{{ $lavaggioHours }}</span>
    @endif
</span>
