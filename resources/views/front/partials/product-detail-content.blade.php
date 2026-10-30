@php
    $isLavaggioProductContent = config('storefront.home') === 'lavaggio';
    $commentFormHasErrors = $errors->has('author_name')
        || $errors->has('author_email')
        || $errors->has('body')
        || $errors->has('rating');
    $commentUser = auth()->user();
    $ratedReviewCount = array_sum($reviewDistribution ?? []);
    $ratedReviewAverage = $ratedReviewCount ? collect($reviewDistribution)->map(fn ($count, $rating) => $count * $rating)->sum() / $ratedReviewCount : 0;

    $hasAttributeSpecifications = $product->relationLoaded('attributes')
        && $product->attributes->contains(
            fn ($attribute): bool => ! $attribute->isMsanManaged() && ! (bool) data_get($attribute->payload, 'filter_only'),
        );
    $technicalSpecificationRows = app(\App\Support\ProductSpecificationPresenter::class)
        ->remainingRows($product, $locale, $fallbackLocale);
    $hasImportedSpecifications = $technicalSpecificationRows->isNotEmpty();
    $hasSpecificationAttributes = $hasAttributeSpecifications || $hasImportedSpecifications;
    $firstDetailSectionId = $hasProductStory
        ? 'product-description'
        : ($hasSpecificationAttributes ? 'product-specifications' : 'product-comments');
    $isHerreraProductContent = \App\Support\StorefrontTheme::usesHerreraLayout((string) ($storeSettings['branding']['store_name'] ?? config('app.name')));
    $productDescriptionHtml = (string) ($translation?->description ?? '');
    if ($isHerreraProductContent) {
        $compactProductSpecifications = $compactProductSpecifications ?? app(\App\Support\ProductSpecificationPresenter::class)
            ->compact($product, $locale, $fallbackLocale, $productDescriptionHtml);
        $productDescriptionHtml = $compactProductSpecifications['description'];
        $hasProductStory = trim(strip_tags($productDescriptionHtml)) !== ''
            || preg_match('~<(?:img|video|iframe|audio)\b~i', $productDescriptionHtml)
            || trim((string) ($translation?->excerpt ?? '')) !== '';
    }
@endphp

@if ($isHerreraProductContent)
    @once
        @push('styles')
            <link rel="stylesheet" href="{{ asset('front-theme/styles/herrera-product-tabs.css') }}?v={{ filemtime(public_path('front-theme/styles/herrera-product-tabs.css')) }}">
        @endpush
    @endonce
@endif

<section class="product-detail-lower" data-product-detail-lower>
    @unless ($isHerreraProductContent)
    <nav class="product-detail-tabs" aria-label="{{ __('ui.product.detail_navigation') }}" data-product-detail-tabs>
        @if ($hasProductStory)
            <a
                href="#product-description"
                class="{{ $firstDetailSectionId === 'product-description' ? 'is-active' : '' }}"
                data-product-detail-tab
                @if ($firstDetailSectionId === 'product-description') aria-current="true" @endif
            >
                <span class="product-detail-tab-description-full">{{ __('ui.product.description') }}</span>
                <span class="product-detail-tab-description-short">{{ __('ui.product.description_short') }}</span>
            </a>
        @endif
        @if ($hasSpecificationAttributes && ! $isLavaggioProductContent)
            <a
                href="#product-specifications"
                class="{{ $firstDetailSectionId === 'product-specifications' ? 'is-active' : '' }}"
                data-product-detail-tab
                @if ($firstDetailSectionId === 'product-specifications') aria-current="true" @endif
            >
                {{ __('ui.product.specifications') }}
            </a>
        @endif
        <a
            href="#product-comments"
            class="{{ $firstDetailSectionId === 'product-comments' ? 'is-active' : '' }}"
            data-product-detail-tab
            @if ($firstDetailSectionId === 'product-comments') aria-current="true" @endif
        >
            {{ __('ui.product.comments_title') }}
        </a>
    </nav>
    @endunless

    @if ($isLavaggioProductContent)
        @include('front.partials.lavaggio-product-legal')
    @elseif ($hasProductStory)
        <section id="product-description" class="product-detail-content-section" data-product-detail-section>
            <header class="product-detail-section-intro">
                <h2 class="product-detail-section-heading">{{ __('ui.product.description') }}</h2>
            </header>

            <div class="product-detail-section-body">
                @if (trim(strip_tags($productDescriptionHtml)) !== '' || preg_match('~<(?:img|video|iframe|audio)\b~i', $productDescriptionHtml))
                    <div class="product-detail-description-content">{!! $productDescriptionHtml !!}</div>
                @elseif (! empty($translation?->excerpt))
                    <p class="product-detail-description-content">{{ $translation->excerpt }}</p>
                @endif
            </div>
        </section>
    @endif

    @if ($hasSpecificationAttributes && ! $isHerreraProductContent && ! $isLavaggioProductContent)
        <section id="product-specifications" class="product-detail-content-section" data-product-detail-section>
            <header class="product-detail-section-intro">
                <h2 class="product-detail-section-heading">{{ __('ui.product.specifications') }}</h2>
            </header>

            <div class="product-detail-section-body">
                @if ($hasAttributeSpecifications)
                    @include('front.partials.product-attribute-panels', [
                        'product' => $product,
                        'locale' => $locale,
                        'fallbackLocale' => $fallbackLocale,
                        'containerClass' => 'product-detail-attribute-panels',
                    ])
                @endif
                @if ($hasImportedSpecifications)
                    @include('front.partials.product-technical-specifications', [
                        'rows' => $technicalSpecificationRows,
                    ])
                @endif
            </div>
        </section>
    @endif

    <section id="product-comments" class="product-comments-anchor product-detail-content-section" data-product-detail-section>
        <header class="product-detail-section-intro product-detail-comments-heading">
            <h2 class="product-detail-section-heading">{{ __('ui.product.comments_title') }}</h2>
            <button
                type="button"
                class="product-detail-comment-toggle"
                data-comment-form-toggle
                aria-expanded="{{ $commentFormHasErrors ? 'true' : 'false' }}"
            >
                {{ __('ui.product.comment_form.toggle') }}
            </button>
        </header>

        <div class="product-detail-section-body">
            <div class="product-rating-overview" aria-label="Prosječna ocjena kupaca">
                @if ($ratedReviewCount > 0)
                    <div class="product-rating-score">
                        <strong>{{ number_format($ratedReviewAverage, 1, ',', '') }}<span> / 5</span></strong>
                        @include('front.partials.product-review-summary', ['count' => $ratedReviewCount, 'average' => $ratedReviewAverage])
                        <p>Prosječna ocjena kupaca</p>
                    </div>
                    <div class="product-rating-distribution" aria-label="Raspodjela ocjena">
                        @for ($rating = 5; $rating >= 1; $rating--)
                            @php($ratingCount = (int) ($reviewDistribution[$rating] ?? 0))
                            <div class="product-rating-bar-row" aria-label="{{ $rating }} zvjezdica: {{ $ratingCount }} ocjena">
                                <span>{{ $rating }} <span aria-hidden="true">★</span></span>
                                <span class="product-rating-track"><span style="width: {{ round($ratingCount / $ratedReviewCount * 100) }}%"></span></span>
                                <span>{{ $ratingCount }}</span>
                            </div>
                        @endfor
                    </div>
                @else
                    <div class="product-rating-empty-stars" aria-hidden="true">☆☆☆☆☆</div>
                    <p>Još nema ocjena. Podijelite svoje iskustvo.</p>
                @endif
            </div>
            <div class="{{ $commentFormHasErrors ? '' : 'hidden' }} product-detail-comment-form" data-comment-form-panel>
                <form method="POST" action="{{ route('products.comments.store', ['slug' => $translation?->slug ?? request()->route('slug')]) }}">
                    @csrf
                    <div class="product-detail-comment-grid">
                        <div class="product-detail-field">
                            <label for="product-comment-author">{{ __('ui.product.comment_form.name') }} <x-front.required-mark :required="! $commentUser" /></label>
                            <input id="product-comment-author" type="text" name="author_name" value="{{ old('author_name', $commentUser?->name ?? '') }}" @if($commentUser) readonly @else required @endif>
                            @error('author_name') <p class="product-detail-field-error">{{ $message }}</p> @enderror
                        </div>
                        <div class="product-detail-field">
                            <label for="product-comment-email">{{ __('ui.product.comment_form.email') }} <x-front.required-mark :required="! $commentUser" /></label>
                            <input id="product-comment-email" type="email" name="author_email" value="{{ old('author_email', $commentUser?->email ?? '') }}" @if($commentUser) readonly @else required @endif>
                            @error('author_email') <p class="product-detail-field-error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="product-detail-field">
                        <label for="product-comment-rating">{{ __('ui.product.comment_form.rating') }}</label>
                        <select id="product-comment-rating" name="rating">
                            <option value="">{{ __('ui.product.comment_form.rating_optional') }}</option>
                            @for ($i = 5; $i >= 1; $i--)
                                <option value="{{ $i }}" @selected((string) old('rating') === (string) $i)>{{ $i }} ★</option>
                            @endfor
                        </select>
                        @error('rating') <p class="product-detail-field-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="product-detail-field">
                        <label for="product-comment-body">{{ __('ui.product.comment_form.body') }} <x-front.required-mark /></label>
                        <textarea id="product-comment-body" name="body" rows="5" required>{{ old('body') }}</textarea>
                        @error('body') <p class="product-detail-field-error">{{ $message }}</p> @enderror
                    </div>

                    <button type="submit" class="product-detail-comment-submit">
                        {{ __('ui.product.comment_form.submit') }}
                    </button>
                </form>
            </div>

            @if (($comments ?? collect())->isNotEmpty())
                <div class="product-detail-comments-list">
                    @foreach ($comments as $comment)
                        <article class="product-detail-comment">
                            <div class="product-detail-comment-meta">
                                <p>{{ $comment->author_name ?: ($comment->user?->name ?? __('ui.product.comments_anonymous')) }}</p>
                                @if ((int) ($comment->rating ?? 0) > 0)
                                    <span class="product-comment-stars" role="img" aria-label="Ocjena {{ (int) $comment->rating }} od 5">
                                        @for ($star = 1; $star <= 5; $star++)<span class="{{ $star <= $comment->rating ? 'is-filled' : '' }}" aria-hidden="true">★</span>@endfor
                                    </span>
                                @endif
                            </div>
                            @if ($comment->created_at)<time class="product-comment-date" datetime="{{ $comment->created_at->toDateString() }}">{{ $comment->created_at->format('d.m.Y.') }}</time>@endif
                            <p class="product-detail-comment-body">{{ $comment->body }}</p>
                        </article>
                    @endforeach
                </div>
            @else
                <p class="product-detail-comments-empty">{{ __('ui.product.comments_empty') }}</p>
            @endif
        </div>
    </section>
</section>
