@if ((bool) ($benefitsBar['enabled'] ?? true) && !empty($benefitsBar['items']))
    <div class="store-benefits-shell storefront-container" @if ($watchlineStorefront ?? false) data-watchline-info-bar @endif>
        <div class="store-benefits-bar" aria-label="{{ __('Prednosti kupnje') }}" aria-live="off" data-store-benefits-rotator>
            @foreach ($benefitsBar['items'] as $benefitIndex => $benefitItem)
                @php
                    $benefitSegments = preg_split(
                        '/(\*\*.+?\*\*)/u',
                        (string) $benefitItem,
                        -1,
                        PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
                    ) ?: [(string) $benefitItem];
                @endphp
                <p class="store-benefits-item {{ $benefitIndex === 0 ? 'is-active' : '' }}" data-store-benefit-item>
                    <span class="store-benefits-copy">
                        @foreach ($benefitSegments as $benefitSegment)
                            @if (str_starts_with($benefitSegment, '**') && str_ends_with($benefitSegment, '**') && mb_strlen($benefitSegment) > 4)
                                <strong>{{ mb_substr($benefitSegment, 2, mb_strlen($benefitSegment) - 4) }}</strong>
                            @else
                                {{ $benefitSegment }}
                            @endif
                        @endforeach
                    </span>
                </p>
            @endforeach
        </div>
    </div>
@endif
