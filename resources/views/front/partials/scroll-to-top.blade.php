@php
    $mobileOffset = (bool) ($mobileOffset ?? false);
    $footerPlacement = (bool) ($footerPlacement ?? false);
    $buttonLabel = app()->getLocale() === 'hr' ? 'Povratak na vrh' : 'Back to top';
@endphp

<button
    type="button"
    class="{{ $footerPlacement ? 'lavaggio-footer-icon-button' : 'scroll-to-top-button'.($mobileOffset ? ' scroll-to-top-button--mobile' : '') }}"
    data-scroll-to-top
    aria-label="{{ $buttonLabel }}"
>
    <x-fa-icon name="arrow-up" />
</button>
