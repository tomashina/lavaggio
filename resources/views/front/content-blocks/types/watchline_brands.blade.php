@php
    $brandWidgetSettings = \App\Support\WatchlineWidgetSettings::forBlock($block, $translation);
    $brandWidgetLocale = (string) app()->getLocale();
    $brandWidgetFallbackLocale = (string) config('app.fallback_locale', 'hr');
    $brandWidgetTitle = trim((string) ($translation?->title ?: $block->name));
    $brandWidgetSubtitle = trim((string) ($translation?->subtitle ?? ''));
    $brandWidgetCtaLabel = trim((string) ($translation?->cta_label ?? ''));
    $brandWidgetCtaUrl = trim((string) ($brandWidgetSettings['cta_url'] ?? ''));
    $brandWidgetEyebrow = trim((string) ($brandWidgetSettings['eyebrow'] ?? ''));
    $brandWidgetHeadingId = 'watchline-brands-title-'.$block->id;
    $brandWidgetVisibleCount = max(1, min(12, (int) ($brandWidgetSettings['visible_count'] ?? 6)));
    $brandWidgetSource = (string) ($brandWidgetSettings['source'] ?? 'manual');

    if ($brandWidgetSource === 'all') {
        $brandWidgetManufacturers = \App\Models\Catalog\Manufacturer\Manufacturer::query()
            ->where('is_active', true)
            ->whereHas('products', fn ($query) => $query->where('is_active', true))
            ->with(['translations', 'media'])
            ->orderBy('sort_order')->orderBy('id')->get();
    } else {
        $brandWidgetManufacturers = new \Illuminate\Database\Eloquent\Collection(($manufacturers ?? collect())->unique('id')->values()->all());
        $brandWidgetManufacturers->loadMissing(['translations', 'media']);
    }

    $brandWidgetPriority = array_flip(array_keys((array) config('herrera-brand-logos', [])));
    $brandWidgetItems = $brandWidgetManufacturers->filter(fn ($manufacturer) => $manufacturer->is_active)
        ->map(function ($manufacturer) use ($brandWidgetLocale, $brandWidgetFallbackLocale, $brandWidgetPriority) {
            $manufacturerTranslation = collect([$brandWidgetLocale, $brandWidgetFallbackLocale])->unique()
                ->map(fn ($locale) => $manufacturer->translations->firstWhere('locale', $locale))
                ->first(fn ($translation) => $translation && trim((string) $translation->slug) !== '');
            if (! $manufacturerTranslation) {
                return null;
            }
            $logo = \App\Support\Media\ManufacturerLogo::resolve($manufacturer);
            if (! $logo['url']) {
                return null;
            }
            $keys = $manufacturer->translations->flatMap(fn ($translation) => [
                (string) $translation->slug, \Illuminate\Support\Str::slug((string) $translation->name),
            ])->push(\Illuminate\Support\Str::slug((string) $manufacturer->code))->unique();
            $key = $keys->first(fn ($key) => isset($brandWidgetPriority[$key]))
                ?? \Illuminate\Support\Str::slug((string) $manufacturerTranslation->name);

            return [
                'id' => (int) $manufacturer->id, 'key' => $key,
                'name' => trim((string) ($manufacturerTranslation->name ?: $manufacturer->code)),
                'slug' => (string) $manufacturerTranslation->slug,
                'logo' => $logo['url'], 'variant' => $logo['variant'],
                'priority' => $brandWidgetPriority[$key] ?? count($brandWidgetPriority),
            ];
        })->filter()->values();
    if ($brandWidgetSource === 'all') {
        $brandWidgetItems = $brandWidgetItems->sortBy('priority')->values();
    }
    $brandWidgetCount = $brandWidgetItems->count();
    $brandWidgetPrevious = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.previous')));
    $brandWidgetNext = trim(str_replace(['&laquo;', '&raquo;'], '', __('pagination.next')));
    $brandWidgetOptions = [
        'type' => $brandWidgetCount > 1 ? 'loop' : 'slide', 'rewind' => false,
        'perPage' => min($brandWidgetVisibleCount, max(1, $brandWidgetCount)),
        'perMove' => 1, 'gap' => '12px', 'drag' => $brandWidgetCount > 1, 'snap' => true,
        'focus' => 0, 'padding' => 0, 'autoWidth' => false, 'pagination' => false,
        'arrows' => $brandWidgetCount > 1, 'updateOnMove' => true, 'speed' => 420,
        'i18n' => ['prev' => $brandWidgetPrevious, 'next' => $brandWidgetNext, 'first' => $brandWidgetNext, 'last' => $brandWidgetPrevious],
        'breakpoints' => [
            1439 => ['perPage' => min(6, $brandWidgetVisibleCount, max(1, $brandWidgetCount))],
            1023 => ['perPage' => min(4, $brandWidgetVisibleCount, max(1, $brandWidgetCount))],
            767 => ['perPage' => min(2, $brandWidgetVisibleCount, max(1, $brandWidgetCount))],
        ],
    ];
@endphp

@if ($brandWidgetItems->isNotEmpty())
    @once('watchline-brands-widget-styles')
        @push('styles')
            <link rel="stylesheet" href="{{ asset('front-theme/styles/herrera-brand-strip.css') }}?v={{ filemtime(public_path('front-theme/styles/herrera-brand-strip.css')) }}">
        @endpush
    @endonce
    @include('front.partials.splide-assets')
    <section class="herrera-home-section herrera-brand-strip" aria-labelledby="{{ $brandWidgetHeadingId }}" data-watchline-brands-widget="{{ $block->id }}">
        <div class="splide herrera-brand-carousel" data-herrera-brand-carousel data-herrera-home-carousel data-watchline-brand-columns="{{ $brandWidgetOptions['perPage'] }}" data-splide='@json($brandWidgetOptions)' aria-labelledby="{{ $brandWidgetHeadingId }}">
            <div class="herrera-home-heading herrera-brand-strip__heading">
                <div>
                    @if ($brandWidgetEyebrow !== '')<p class="herrera-home-section-eyebrow">{{ $brandWidgetEyebrow }}</p>@endif
                    <h2 id="{{ $brandWidgetHeadingId }}">{{ $brandWidgetTitle }}</h2>
                    @if ($brandWidgetSubtitle !== '')<p class="herrera-home-section-subtitle">{{ $brandWidgetSubtitle }}</p>@endif
                </div>
                <div class="herrera-brand-strip__actions">
                    @if ($brandWidgetCtaLabel !== '' && $brandWidgetCtaUrl !== '')
                        <a class="herrera-brand-strip__all" href="{{ $brandWidgetCtaUrl }}">{{ $brandWidgetCtaLabel }} <x-fa-icon name="arrow-right" /></a>
                    @endif
                    @if ($brandWidgetCount > 1)
                        <div class="splide__arrows">
                            <button class="splide__arrow splide__arrow--prev" type="button" aria-label="{{ $brandWidgetPrevious }}" disabled><x-fa-icon name="chevron-right" style="solid" /></button>
                            <button class="splide__arrow splide__arrow--next" type="button" aria-label="{{ $brandWidgetNext }}" disabled><x-fa-icon name="chevron-right" style="solid" /></button>
                        </div>
                    @endif
                </div>
            </div>
            <div class="splide__track"><div class="splide__list herrera-brand-strip__logos">
                @foreach ($brandWidgetItems as $brandWidgetItem)
                    <div class="splide__slide">
                        <a class="herrera-brand-strip__brand" href="{{ route('manufacturers.show', ['slug' => $brandWidgetItem['slug']]) }}" aria-label="{{ __('herrera.brand_browse', ['name' => $brandWidgetItem['name']]) }}" data-herrera-brand="{{ $brandWidgetItem['key'] }}" data-watchline-brand-id="{{ $brandWidgetItem['id'] }}">
                            <span class="herrera-brand-strip__image {{ $brandWidgetItem['variant'] !== '' ? 'herrera-brand-strip__image--'.$brandWidgetItem['variant'] : '' }}">
                                <img src="{{ $brandWidgetItem['logo'] }}" alt="{{ $brandWidgetItem['name'] }}" width="160" height="64" loading="lazy" decoding="async">
                            </span>
                        </a>
                    </div>
                @endforeach
            </div></div>
        </div>
    </section>
    @include('front.partials.herrera-home-carousel-script')
@endif
