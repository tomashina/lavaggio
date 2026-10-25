@php
    $reviewProduct = $featuredReview->commentable;
    $reviewProductTranslation = $reviewProduct->translations->firstWhere('locale', app()->getLocale())
        ?? $reviewProduct->translations->firstWhere('locale', config('app.fallback_locale', 'hr'));
    $reviewProductUrl = route('products.show', ['slug' => $reviewProductTranslation->slug]).'#product-comments';
    $reviewAuthor = trim((string) ($featuredReview->author_name ?: $featuredReview->user?->name)) ?: __('ui.product.comments_anonymous');
    $reviewInitial = mb_strtoupper(mb_substr($reviewAuthor, 0, 1));
    $reviewRating = (int) $featuredReview->rating;
    $reviewProductImage = \App\Support\Media\LegacyCatalogImage::first($reviewProduct, ['thumb_100x100', 'card_320w'], width: 160);
@endphp
    <article class="lm-home-review" data-home-review-rating="{{ $reviewRating }}">
        <div class="lm-home-review-rating" aria-label="{{ __('Ocjena :rating od 5', ['rating' => $reviewRating]) }}">
            <span class="lm-home-review-stars" aria-hidden="true">
                @for ($star = 1; $star <= 5; $star++)
                    <x-fa-icon name="star" class="{{ $star <= $reviewRating ? 'is-filled' : '' }}" />
                @endfor
            </span>
            <span aria-hidden="true">{{ $reviewRating }},0 <span>/ 5</span></span>
        </div>
        <blockquote>{{ ($reviewShowFullBody ?? false) ? (string) $featuredReview->body : \Illuminate\Support\Str::limit((string) $featuredReview->body, 320) }}</blockquote>
        <footer>
            <div class="lm-home-review-author">
                <span class="lm-home-review-avatar" aria-hidden="true">{{ $reviewInitial }}</span>
                <div>
                    <strong>{{ $reviewAuthor }}</strong>
                    @if ($featuredReview->created_at)
                        <time datetime="{{ $featuredReview->created_at->toDateString() }}">{{ $featuredReview->created_at->format('d.m.Y.') }}</time>
                    @endif
                </div>
            </div>
            <a class="lm-home-review-product" href="{{ $reviewProductUrl }}">
                <span class="lm-home-review-product-image" aria-hidden="true">
                    @if ($reviewProductImage)
                        <img src="{{ $reviewProductImage }}" alt="" width="56" height="56" loading="lazy" decoding="async" data-home-review-product-image>
                    @else
                        <x-fa-icon name="gift" class="lm-home-review-product-placeholder" data-home-review-product-placeholder />
                    @endif
                </span>
                <span class="lm-home-review-product-label"><small>{{ __('O proizvodu') }}</small><strong>{{ $reviewProductTranslation->name }}</strong></span>
                <x-fa-icon name="arrow-right" />
            </a>
        </footer>
    </article>
