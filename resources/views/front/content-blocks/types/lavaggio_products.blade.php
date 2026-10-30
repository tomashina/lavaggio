@php
    $settings = \App\Support\WatchlineWidgetSettings::forBlock($block, $translation);
    $widgetProducts = app(\App\Services\Content\WatchlineWidgetProducts::class)->forBlock($block, $translation, $products);
    $widgetCount = $widgetProducts->count();
    $headingId = 'lavaggio-products-'.$block->id;
    $desktopColumns = min(max(1, $widgetCount), max(1, min(6, (int) ($settings['desktop_columns'] ?? 6))));
    $tabletColumns = min($desktopColumns, max(1, min(4, (int) ($settings['tablet_columns'] ?? 4))));
    $mobileColumns = min($tabletColumns, max(1, min(2, (int) ($settings['mobile_columns'] ?? 2))));
    $previousLabel = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.previous')));
    $nextLabel = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.next')));
    $options = [
        'type' => 'slide', 'rewind' => true, 'perPage' => $desktopColumns, 'perMove' => 1,
        'gap' => '0rem', 'pagination' => false, 'arrows' => $widgetCount > $desktopColumns,
        'destroy' => $widgetCount <= $desktopColumns, 'drag' => $widgetCount > 1,
        'snap' => true, 'updateOnMove' => true, 'speed' => 420,
        'i18n' => ['prev' => $previousLabel, 'next' => $nextLabel],
        'breakpoints' => [
            1279 => ['perPage' => $tabletColumns, 'destroy' => $widgetCount <= $tabletColumns, 'arrows' => $widgetCount > $tabletColumns],
            767 => ['perPage' => $mobileColumns, 'destroy' => $widgetCount <= $mobileColumns, 'arrows' => $widgetCount > $mobileColumns],
        ],
    ];
@endphp
@if ($widgetProducts->isNotEmpty())
    @include('front.partials.splide-assets')
    @once('lavaggio-home-products-assets')
        @push('styles')
            <link rel="stylesheet" href="{{ asset('front-theme/styles/lavaggio-home-products.css') }}?v={{ filemtime(public_path('front-theme/styles/lavaggio-home-products.css')) }}">
        @endpush
    @endonce
    <section class="lavaggio-widget-products" aria-labelledby="{{ $headingId }}" data-lavaggio-products-widget="{{ $block->code }}"
        style="--lavaggio-product-columns:{{ $desktopColumns }};--lavaggio-product-tablet-columns:{{ $tabletColumns }};--lavaggio-product-mobile-columns:{{ $mobileColumns }}">
        <div class="splide lavaggio-widget-product-carousel" data-herrera-home-carousel data-continuous-card-carousel
            data-desktop-cols="{{ $desktopColumns }}" data-mobile-cols="{{ $mobileColumns }}" data-product-count="{{ $widgetCount }}"
            data-splide='@json($options)' aria-labelledby="{{ $headingId }}">
            <header class="lavaggio-widget-products-heading">
                <div>
                    @if (filled($settings['eyebrow'] ?? ''))<p class="lavaggio-widget-eyebrow">{{ $settings['eyebrow'] }}</p>@endif
                    <h2 id="{{ $headingId }}">{{ $settings['title'] ?? $block->name }}</h2>
                    @if (filled($settings['subtitle'] ?? ''))<p class="lavaggio-widget-subtitle">{{ $settings['subtitle'] }}</p>@endif
                </div>
                <div class="lavaggio-widget-product-actions">
                    @if (filled($settings['cta_label'] ?? '') && filled($settings['cta_url'] ?? ''))
                        <a class="lavaggio-widget-products-link" href="{{ $settings['cta_url'] }}">{{ $settings['cta_label'] }} <x-fa-icon name="arrow-right" /></a>
                    @endif
                    @if ($widgetCount > $mobileColumns)
                        <div class="splide__arrows">
                            <button class="splide__arrow splide__arrow--prev" type="button" aria-label="{{ $previousLabel }}" disabled><x-fa-icon name="chevron-right" style="solid" /></button>
                            <button class="splide__arrow splide__arrow--next" type="button" aria-label="{{ $nextLabel }}" disabled><x-fa-icon name="chevron-right" style="solid" /></button>
                        </div>
                    @endif
                </div>
            </header>
            <div class="splide__track"><ul class="splide__list">
                @foreach ($widgetProducts as $widgetProduct)
                    <li class="splide__slide"><x-front.desktop.product-card :product="$widgetProduct" :locale="$locale" :fallback-locale="$fallbackLocale" :lined="true" :flat="true" image-sizes="(min-width: 1280px) calc((100vw - 80px) / 6), (min-width: 768px) 24vw, 48vw" /></li>
                @endforeach
            </ul></div>
        </div>
    </section>
    @include('front.partials.herrera-home-carousel-script')
@endif
