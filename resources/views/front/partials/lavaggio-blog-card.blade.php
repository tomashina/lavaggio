@php
    $cardTranslation = $blogCardPost->translations->firstWhere('locale', $blogCardLocale)
        ?? $blogCardPost->translations->firstWhere('locale', $blogCardFallbackLocale);
    $cardTitle = (string) ($cardTranslation?->title ?: $blogCardPost->code);
    $cardUrl = route('blog.show', ['slug' => $cardTranslation?->slug ?: $blogCardPost->id]);
    $cardImage = \App\Support\Media\HerreraBlogImage::first($blogCardPost);
    $cardIsPortrait = str_contains((string) $cardTranslation?->body_html, 'lavaggio-content-video-portrait');
    $cardCategories = $blogCardPost->categories->sortBy(fn ($category) => (int) ($category->pivot?->sort_order ?? 0));
@endphp
<article class="lavaggio-blog-card" data-lavaggio-blog-card>
    <a href="{{ $cardUrl }}" class="lavaggio-blog-card-image {{ $cardIsPortrait ? 'is-portrait' : '' }}" aria-label="{{ $cardTitle }}">
        @if ($cardImage)
            <img src="{{ $cardImage }}" alt="{{ $cardTitle }}" width="480" height="360" loading="lazy" decoding="async">
            <span class="lavaggio-blog-card-play" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z" /></svg></span>
        @else
            <span>{{ __('ui.product.no_image') }}</span>
        @endif
    </a>
    <div class="lavaggio-blog-card-copy">
        @if ($cardCategories->isNotEmpty())
            <div class="lavaggio-blog-card-categories" aria-label="Kategorije objave">
                @foreach ($cardCategories as $cardCategory)
                    @php($cardCategoryTranslation = $cardCategory->translations->firstWhere('locale', $blogCardLocale) ?? $cardCategory->translations->firstWhere('locale', $blogCardFallbackLocale))
                    @if ($cardCategoryTranslation)
                        <a class="lavaggio-blog-category-badge" href="{{ route('blog.index', ['category' => $cardCategoryTranslation->slug]) }}">{{ $cardCategoryTranslation->name }}</a>
                    @endif
                @endforeach
            </div>
        @endif
        <h2><a href="{{ $cardUrl }}">{{ $cardTitle }}</a></h2>
        @if ($cardTranslation?->excerpt)
            <p>{{ $cardTranslation->excerpt }}</p>
        @endif
        @if ($blogCardPost->published_at)
            <time datetime="{{ $blogCardPost->published_at->toDateString() }}">{{ $blogCardPost->published_at->format('d. m. Y.') }}</time>
        @endif
    </div>
</article>
