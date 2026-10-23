@php
    $watchlineBenefits = \App\Support\WatchlineWidgetSettings::forBlock(
        ($block ?? null) instanceof \App\Models\Content\ContentBlock ? $block : new \App\Models\Content\ContentBlock(['type' => 'watchline_benefits']),
        ($translation ?? null) instanceof \App\Models\Content\ContentBlockTranslation ? $translation : null,
    );
@endphp
<section class="lm-benefits" aria-label="{{ $watchlineBenefits['aria_label'] }}" data-libermedia-home-benefits>
    @foreach ([1, 2, 3, 4] as $watchlineBenefit)
        @if ($watchlineBenefits['item_'.$watchlineBenefit.'_url'] !== '')
            <a href="{{ $watchlineBenefits['item_'.$watchlineBenefit.'_url'] }}">
        @else
            <div>
        @endif
                <x-fa-icon :name="$watchlineBenefits['item_'.$watchlineBenefit.'_icon']" style="regular" :inline="true" />
                <span><strong>{!! nl2br(e((string) $watchlineBenefits['item_'.$watchlineBenefit.'_title'])) !!}</strong><small>{!! nl2br(e((string) $watchlineBenefits['item_'.$watchlineBenefit.'_description'])) !!}</small></span>
        @if ($watchlineBenefits['item_'.$watchlineBenefit.'_url'] !== '')
            </a>
        @else
            </div>
        @endif
    @endforeach
</section>
