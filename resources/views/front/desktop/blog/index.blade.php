@extends('front.desktop.layouts.store')

@php($isLavaggioStudio = config('storefront.home') === 'lavaggio')
@section('title', ($blogCategoryTranslation?->meta_title ?? null) ?: ($blogCategoryTranslation?->name ?? null) ?: ($isLavaggioStudio ? 'TV studio | Lavaggio' : __('ui.blog.page_title')))
@section('main_class', 'mx-auto w-full max-w-7xl px-6 pt-0 pb-0')

@section('content')
    @php($isLibermediaBlog = \App\Support\StorefrontTheme::usesLibermediaBrand((string) ($storeSettings['branding']['store_name'] ?? '')))
    @once
        @push('styles')
            <link rel="stylesheet" href="{{ asset('front-theme/styles/herrera-home-blog.css') }}?v={{ filemtime(public_path('front-theme/styles/herrera-home-blog.css')) }}">
            @if ($isLavaggioStudio)
                <link rel="stylesheet" href="{{ asset('front-theme/styles/lavaggio-blog.css') }}?v={{ filemtime(public_path('front-theme/styles/lavaggio-blog.css')) }}">
            @endif
        @endpush
    @endonce
    <section class="mb-8 px-1">
        <div class="front-soft-hero lm-page-title px-6 py-4 text-center sm:px-8 sm:py-5">
            <nav aria-label="Breadcrumb" class="mb-2">
                <ol class="flex flex-wrap items-center justify-center gap-1.5 text-[11px] font-medium uppercase tracking-wide text-slate-500 sm:gap-2">
                    <li><a href="{{ route('home') }}" class="hover:text-slate-700"><x-front.breadcrumb-home /></a></li>
                    <li class="text-slate-400">/</li>
                    <li class="text-slate-700">{{ $isLavaggioStudio ? 'TV studio' : __('ui.blog.title') }}</li>
                </ol>
            </nav>
            <h1 class="text-2xl font-extrabold uppercase tracking-tight text-slate-900">{{ ($blogCategoryTranslation?->name ?? null) ?: ($isLavaggioStudio ? 'TV studio' : __('ui.blog.title')) }}</h1>
            @unless ($isLavaggioStudio)
                <p class="mt-1 text-xs font-medium uppercase tracking-wide text-slate-500">{{ config('storefront.home') === 'watchline' ? 'Novosti i savjeti iz svijeta satova, naočala i nakita' : ($isLibermediaBlog ? 'Ideje za igru, učenje i odrastanje' : (str_contains(strtolower((string) ($storeSettings['branding']['store_name'] ?? '')), 'herrera') ? __('herrera.blog.subtitle') : __('ui.blog.subtitle'))) }}</p>
            @endunless
        </div>
    </section>

    @if ($topBlocks->isNotEmpty())
        <section class="mb-8">@include('components.content-placement', ['items' => $topBlocks])</section>
    @endif

    @if ($isLavaggioStudio && $blogFilterCategories->isNotEmpty())
        <form action="{{ route('blog.index') }}" method="get" class="lavaggio-blog-filter" aria-label="Filtriranje TV studija">
            <label for="lavaggio-blog-category">Kategorija</label>
            <select id="lavaggio-blog-category" name="category">
                <option value="">Sve kategorije</option>
                @foreach ($blogFilterCategories as $filterCategory)
                    @php($filterTranslation = $filterCategory->translations->firstWhere('locale', $locale) ?? $filterCategory->translations->firstWhere('locale', $fallbackLocale))
                    @if ($filterTranslation)
                        <option value="{{ $filterTranslation->slug }}" @selected($blogCategory?->id === $filterCategory->id)>{{ $filterTranslation->name }}</option>
                    @endif
                @endforeach
            </select>
            <button type="submit">Prikaži</button>
            @if ($blogCategory)
                <a href="{{ route('blog.index') }}" class="lavaggio-blog-filter-clear">Prikaži sve</a>
            @endif
        </form>
    @endif

    @if ($posts->isEmpty())
        <div class="border border-dashed border-slate-300 bg-white p-10 text-center text-slate-500">{{ $isLavaggioStudio ? 'Još nema objava u TV studiju.' : __('ui.blog.empty') }}</div>
    @else
        <section class="{{ $isLavaggioStudio ? 'lavaggio-blog-grid' : 'grid gap-x-8 gap-y-12 sm:grid-cols-2 lg:grid-cols-3' }}" @if($isLavaggioStudio) data-lavaggio-blog-grid @endif>
            @foreach ($posts as $post)
                @if ($isLavaggioStudio)
                    @include('front.partials.lavaggio-blog-card', ['blogCardPost' => $post, 'blogCardLocale' => $locale, 'blogCardFallbackLocale' => $fallbackLocale])
                @else
                    @include('front.partials.herrera-blog-card', ['blogCardPost' => $post, 'blogCardLocale' => $locale, 'blogCardFallbackLocale' => $fallbackLocale, 'blogCardInList' => true, 'blogCardShowReadMore' => $isLibermediaBlog])
                @endif
            @endforeach
        </section>

        <div class="mt-10">{{ $posts->links() }}</div>
    @endif

    @if ($bottomBlocks->isNotEmpty())
        <section class="mt-10">@include('components.content-placement', ['items' => $bottomBlocks])</section>
    @endif
@endsection
