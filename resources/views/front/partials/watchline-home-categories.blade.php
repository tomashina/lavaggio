@php
    $watchlineCategoryLocale = (string) app()->getLocale();
    $watchlineCategoryContent = app(\App\Support\WatchlineHomeContent::class);
    $watchlineCategories = \App\Models\Catalog\Category\Category::query()
        ->where('scope', 'catalog')->currentlyVisible()->whereNull('parent_id')
        ->with(['translations', 'media'])->orderBy('sort_order')->orderBy('id')->get();
    $watchlineCategoryDetails = [
        'satovi' => ['label' => 'Satovi', 'description' => 'Za svaki vaš trenutak.', 'icon' => 'watch'],
        'naocale' => ['label' => 'Naočale', 'description' => 'Jasan pogled. Osoban stil.', 'icon' => 'sunglasses'],
        'nakit' => ['label' => 'Nakit', 'description' => 'Detalji koji ostaju.', 'icon' => 'ring'],
    ];
@endphp
@if ($watchlineCategories->isNotEmpty())
    <section class="wl-categories" aria-labelledby="watchline-categories-title" data-watchline-categories>
        <header class="wl-section-heading">
            <div><p class="wl-eyebrow">VAŠ STIL, VAŠ IZBOR</p><h2 id="watchline-categories-title">Pronađite svoj detalj.</h2></div>
            <a href="{{ route('categories.index') }}">Cijela ponuda <x-fa-icon name="arrow-right" /></a>
        </header>
        <div class="wl-categories-grid">
            @foreach ($watchlineCategories as $watchlineCategory)
                @php
                    $watchlineCategoryTranslation = $watchlineCategory->translations->firstWhere('locale', $watchlineCategoryLocale) ?? $watchlineCategory->translations->first();
                    $watchlineCategorySlug = (string) $watchlineCategoryTranslation?->slug;
                    $watchlineCategoryDetail = $watchlineCategoryDetails[$watchlineCategorySlug] ?? ['label' => $watchlineCategoryTranslation?->name, 'description' => '', 'icon' => 'gem'];
                    $watchlineCategoryImage = \App\Support\Media\LegacyCatalogImage::first($watchlineCategory) ?? $watchlineCategoryContent->categoryImage($watchlineCategory);
                @endphp
                @if ($watchlineCategorySlug !== '')
                    <a class="wl-category-card" href="{{ route('categories.show', ['slug' => $watchlineCategorySlug]) }}">
                        <span class="wl-category-art" aria-hidden="true">
                            @if ($watchlineCategoryImage)
                                <img src="{{ $watchlineCategoryImage }}" width="480" height="300" alt="" loading="lazy" decoding="async">
                            @else
                                <x-fa-icon :name="$watchlineCategoryDetail['icon']" style="light" />
                            @endif
                        </span>
                        <span class="wl-category-copy"><span><strong>{{ $watchlineCategoryDetail['label'] }}</strong><small>{{ $watchlineCategoryDetail['description'] }}</small></span><x-fa-icon name="arrow-up-right" /></span>
                    </a>
                @endif
            @endforeach
        </div>
    </section>
@endif
