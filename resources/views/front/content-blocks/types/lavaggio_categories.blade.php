@php($settings = \App\Support\WatchlineWidgetSettings::forBlock($block, $translation))
<section class="lavaggio-widget-section lavaggio-widget-categories" aria-labelledby="lavaggio-categories-{{ $block->id }}">
    <header class="lavaggio-widget-heading">
        <div>@if(filled($settings['eyebrow']))<p class="lavaggio-widget-eyebrow">{{ $settings['eyebrow'] }}</p>@endif<h2 id="lavaggio-categories-{{ $block->id }}">{{ $translation?->title }}</h2></div>
        @if(filled($translation?->cta_label) && filled($settings['cta_url'] ?? ''))<a href="{{ $settings['cta_url'] }}">{{ $translation->cta_label }} <x-fa-icon name="arrow-right" /></a>@endif
    </header>
    <div class="lavaggio-widget-category-grid">
        @foreach(range(1, 5) as $index)
            @if(filled($settings['card_'.$index.'_title']) && filled($settings['card_'.$index.'_url']))
                <a class="lavaggio-widget-category-card" href="{{ $settings['card_'.$index.'_url'] }}">
                    <div class="lavaggio-widget-category-card__image">@if(filled($settings['card_'.$index.'_image']))<img src="{{ $settings['card_'.$index.'_image'] }}" alt="{{ $settings['card_'.$index.'_image_alt'] }}" width="800" height="600" loading="lazy" decoding="async">@endif</div>
                    <div class="lavaggio-widget-category-card__copy">
                        <div>
                            <div class="lavaggio-widget-category-card__title"><span class="lavaggio-widget-category-icon"><x-fa-icon :name="$settings['card_'.$index.'_icon']" style="light" :inline="true" /></span><h3>{{ $settings['card_'.$index.'_title'] }}</h3></div>
                        </div>
                    </div>
                </a>
            @endif
        @endforeach
        @if(filled($settings['card_6_title']) && filled($settings['card_6_url']))
            <a class="lavaggio-widget-category-card lavaggio-widget-category-card--bundle" href="{{ $settings['card_6_url'] }}" data-lavaggio-home-bundle-promo>
                <div class="lavaggio-widget-bundle-intro"><span class="lavaggio-widget-category-icon"><x-fa-icon :name="$settings['card_6_icon']" style="light" :inline="true" /></span></div>
                <h3 class="lavaggio-widget-bundle-title">{{ $settings['card_6_title'] }}</h3>
                @if(filled($settings['card_6_cta_label']))<span class="lavaggio-widget-bundle-cta">{{ $settings['card_6_cta_label'] }} <x-fa-icon name="arrow-right" /></span>@endif
            </a>
        @endif
    </div>
</section>
