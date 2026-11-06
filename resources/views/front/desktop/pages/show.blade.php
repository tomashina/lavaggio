@extends('front.desktop.layouts.store')

@php
    $translation = $selectedTranslation
        ?? $page->translations->firstWhere('locale', $locale)
        ?? $page->translations->firstWhere('locale', $fallbackLocale);
    $pageLinkCards = app(\App\Support\Lavaggio\InfoPageLinkCards::class)->fromHtml((string) $translation?->body_html);
    $isLavaggio = config('storefront.home') === 'lavaggio';
    $isReviewPage = $isLavaggio && ($page->code === 'lavaggio-modx-78' || $translation?->slug === 'recenzije-klijenata');
    $isDeliveryPage = $isLavaggio && ($page->code === 'lavaggio-shop-dostava' || $translation?->slug === 'dostava');
    $deliverySections = $isDeliveryPage ? app(\App\Support\Lavaggio\DeliveryPageSections::class)->fromHtml((string) $translation?->body_html) : null;
    $isPricingPage = $isLavaggio && $page->code === 'lavaggio-modx-88';
    $pageBody = (string) $translation?->body_html;
    if ($isPricingPage) {
        $pageBody = app(\App\Support\Lavaggio\ServicePricingAnchorPrices::class)->render($pageBody);
    }
@endphp

@if (config('storefront.home') === 'lavaggio')
    @push('head')
        <link rel="stylesheet" href="{{ asset('front-theme/styles/lavaggio-content-pages.css') }}?v={{ filemtime(public_path('front-theme/styles/lavaggio-content-pages.css')) }}">
        @if ($isPricingPage)
            <link rel="stylesheet" href="{{ asset('front-theme/styles/lavaggio-service-pricing.css') }}?v={{ filemtime(public_path('front-theme/styles/lavaggio-service-pricing.css')) }}">
        @endif
    @endpush
@endif

@section('title', $translation?->title ?? 'Page')
@section('main_class', 'mx-auto w-full max-w-7xl px-6 pt-0 pb-8')

@section('content')
    @if (config('storefront.home') === 'lavaggio')
        <div class="lavaggio-info-page{{ $pageLinkCards !== [] ? ' lavaggio-info-page--links' : '' }}{{ $isReviewPage ? ' lavaggio-info-page--reviews' : '' }}{{ $isDeliveryPage ? ' lavaggio-info-page--delivery' : '' }}{{ $isPricingPage ? ' lavaggio-info-page--pricing' : '' }}">
    @endif
    @if ($topBlocks->isNotEmpty())
        <section class="mb-8">@include('components.content-placement', ['items' => $topBlocks])</section>
    @endif

    <section class="lavaggio-content-title mb-8 px-1">
        <div class="front-soft-hero lm-page-title px-6 py-4 text-center sm:px-8 sm:py-5">
            <nav aria-label="Breadcrumb" class="mb-2">
                <ol class="flex flex-wrap items-center justify-center gap-1.5 text-[11px] font-medium uppercase tracking-wide text-slate-500 sm:gap-2">
                    <li><a href="{{ route('home') }}" class="hover:text-slate-700"><x-front.breadcrumb-home /></a></li>
                    <li class="text-slate-400">/</li>
                    <li class="text-slate-700">{{ $translation?->title ?? $page->code }}</li>
                </ol>
            </nav>
            <h1 class="text-2xl font-extrabold uppercase tracking-tight text-slate-900">{{ $translation?->title ?? $page->code }}</h1>
            @if (config('storefront.home') !== 'lavaggio' && !empty($translation?->excerpt))
                <p class="mt-1 text-xs font-medium uppercase tracking-wide text-slate-500">{{ $translation->excerpt }}</p>
            @endif
        </div>
    </section>

    @if ($pageLinkCards !== [])
        <nav class="lavaggio-page-link-cards" aria-label="{{ $translation?->title ?? $page->code }}">
            @foreach ($pageLinkCards as $card)
                <a class="lavaggio-page-link-card" href="{{ $card['url'] }}">
                    <span class="lavaggio-page-link-icon" aria-hidden="true"><x-fa-icon :name="$card['icon']" /></span>
                    <span class="lavaggio-page-link-label">{{ $card['title'] }}</span>
                    <x-fa-icon name="arrow-right" class="lavaggio-page-link-arrow" />
                </a>
            @endforeach
        </nav>
    @elseif ($deliverySections)
        @include('front.desktop.pages.partials.lavaggio-delivery')
    @else
    <article class="lavaggio-content-article bg-white px-2 py-2">
        <div class="content-richtext">
            {!! app(\App\Support\WatchlineArticleImages::class)->render($pageBody) ?: '<p>This page has no body content.</p>' !!}
        </div>
    </article>
    @endif

    @if ($bottomBlocks->isNotEmpty())
        <section class="mt-10">@include('components.content-placement', ['items' => $bottomBlocks])</section>
    @endif
    @if (config('storefront.home') === 'lavaggio')
        </div>
    @endif
@endsection
