@php
    $blogCardTranslation = $blogCardPost->translations->firstWhere('locale', $blogCardLocale)
        ?? $blogCardPost->translations->firstWhere('locale', $blogCardFallbackLocale);
    $blogCardTitle = (string) ($blogCardTranslation?->title ?: $blogCardPost->code);
    $blogCardImage = \App\Support\Media\HerreraBlogImage::first($blogCardPost);
    $blogCardVariant = \App\Support\Media\LegacyCatalogImage::responsiveVariant($blogCardImage);
@endphp
<article class="herrera-blog-card">
    <a href="{{ route('blog.show', ['slug' => $blogCardTranslation?->slug ?: $blogCardPost->id]) }}">
        <div class="herrera-blog-card-image aspect-[3/2]">
            @if ($blogCardImage)
                <img src="{{ $blogCardVariant['url'] ?? $blogCardImage }}" @if ($blogCardVariant) srcset="{{ $blogCardVariant['srcset'] }}" sizes="(max-width: 767px) calc(100vw - 2rem), (max-width: 1023px) 45vw, 32vw" @endif alt="{{ $blogCardTitle }}" width="{{ $blogCardVariant['width'] ?? 1536 }}" height="{{ $blogCardVariant['height'] ?? 1024 }}" loading="lazy" decoding="async">
            @else
                <span>{{ __('ui.product.no_image') }}</span>
            @endif
        </div>
        <div class="herrera-blog-card-copy">
            @if ($blogCardInList ?? false)
                <h2>{{ $blogCardTitle }}</h2>
            @else
                <h3>{{ $blogCardTitle }}</h3>
            @endif
            <p>{{ $blogCardTranslation?->excerpt ?: __('ui.blog.excerpt_fallback') }}</p>
            @if ($blogCardShowReadMore ?? false)
                <span class="herrera-blog-card-read">Pročitaj članak <x-fa-icon name="arrow-right" /></span>
            @endif
        </div>
    </a>
</article>
