@once('lavaggio-home-hero-styles')
    @push('styles')
        <link rel="stylesheet" href="{{ asset('front-theme/styles/lavaggio-home-hero.css') }}?v={{ filemtime(public_path('front-theme/styles/lavaggio-home-hero.css')) }}">
    @endpush
@endonce
@php
    $settings = \App\Support\WatchlineWidgetSettings::forBlock($block, $translation);
    $heroDefaults = \App\Support\WatchlineWidgetSettings::defaults('lavaggio_campaigns');
    // Retire the old generated montage while retaining custom CMS photography.
    $legacyMontage = '/assets/lavaggio/home/lavaggio-product-group-v1.webp';
    $mainImage = $settings['main_image'] === $legacyMontage ? $heroDefaults['main_image'] : $settings['main_image'];
    $catalogueImages = in_array($settings['main_image'], [$legacyMontage, $heroDefaults['main_image']], true);
@endphp
<section class="lavaggio-widget-hero lavaggio-widget-hero--photographic" aria-labelledby="lavaggio-hero-{{ $block->id }}">
    <article class="lavaggio-widget-hero__main">
        <div class="lavaggio-widget-hero__copy">
            @if(filled($settings['eyebrow']))<p class="lavaggio-widget-eyebrow">{{ $settings['eyebrow'] }}</p>@endif
            <h1 id="lavaggio-hero-{{ $block->id }}">{{ $translation?->title }}</h1>
            @if(filled($translation?->subtitle))<p class="lavaggio-widget-description">{{ $translation->subtitle }}</p>@endif
            @if($settings['shipping_notice'])
                <p class="lavaggio-widget-shipping"><x-fa-icon name="truck-fast" /><span>Besplatna dostava iznad <strong>{{ app(\App\Services\Settings\SystemSettingsService::class)->get('lavaggio_free_shipping_threshold', 50) }} €</strong></span></p>
            @endif
            @if(filled($translation?->cta_label) && filled($settings['cta_url'] ?? ''))<a class="lavaggio-widget-button" href="{{ $settings['cta_url'] }}">{{ $translation->cta_label }} <x-fa-icon name="arrow-right" /></a>@endif
        </div>
        @if(filled($mainImage))
            <div @class(['lavaggio-widget-hero__photographs', 'lavaggio-widget-hero__photographs--single' => ! $catalogueImages])>
                <img class="lavaggio-widget-hero__product lavaggio-widget-hero__product--main" src="{{ $mainImage }}" alt="{{ $settings['main_image_alt'] }}" width="480" height="911" fetchpriority="high" decoding="async">
                @if($catalogueImages)
                    @foreach(['secondary', 'tertiary'] as $productPhoto)
                        @if(filled($settings['main_'.$productPhoto.'_image']))
                            <img class="lavaggio-widget-hero__product lavaggio-widget-hero__product--{{ $productPhoto }}" src="{{ $settings['main_'.$productPhoto.'_image'] }}" alt="{{ $settings['main_'.$productPhoto.'_image_alt'] }}" width="480" height="{{ $productPhoto === 'secondary' ? 1249 : 1058 }}" decoding="async">
                        @endif
                    @endforeach
                @endif
            </div>
        @endif
    </article>
    @foreach(['service', 'self'] as $panel)
        <article @class(['lavaggio-widget-hero__service', 'lavaggio-widget-hero__service--'.$panel, 'lavaggio-widget-hero__service--with-photo' => filled($settings[$panel.'_image'])])>
            <div class="lavaggio-widget-hero__service-copy">
                @if(filled($settings[$panel.'_eyebrow']))<p class="lavaggio-widget-eyebrow">{{ $settings[$panel.'_eyebrow'] }}</p>@endif
                <h2>{{ $settings[$panel.'_title'] }}</h2>
                @if(filled($settings[$panel.'_description']))<p class="lavaggio-widget-description">{{ $settings[$panel.'_description'] }}</p>@endif
                @if(filled($settings[$panel.'_url']) && filled($settings[$panel.'_cta_label']))<a class="lavaggio-widget-hero__service-link" href="{{ $settings[$panel.'_url'] }}">{{ $settings[$panel.'_cta_label'] }} <x-fa-icon name="arrow-right" /></a>@endif
            </div>
            @if(filled($settings[$panel.'_image']))
                <div class="lavaggio-widget-hero__service-photo"><img src="{{ $settings[$panel.'_image'] }}" alt="{{ $settings[$panel.'_image_alt'] }}" width="1000" height="750" decoding="async"></div>
            @endif
        </article>
    @endforeach
</section>
