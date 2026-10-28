@extends('front.desktop.layouts.store')

@php
    $faqTitle = ($faqPageTranslation?->title ?? null) ?: __('ui.faq.title');
@endphp
@section('title', ($faqPageTranslation?->meta_title ?? null) ?: ($faqPageTranslation?->title ?? null) ?: __('ui.faq.page_title'))
@section('main_class', 'mx-auto w-full max-w-7xl px-6 pt-0 pb-0')

@push('styles')
    @if (config('storefront.home') === 'lavaggio')
        <link rel="stylesheet" href="{{ asset('front-theme/styles/lavaggio-faq.css') }}?v={{ filemtime(public_path('front-theme/styles/lavaggio-faq.css')) }}">
    @endif
@endpush

@section('content')
    @if ($topBlocks->isNotEmpty())
        <section class="mb-8">@include('components.content-placement', ['items' => $topBlocks])</section>
    @endif

    @if (config('storefront.home') === 'lavaggio')
        @include('front.desktop.faq.lavaggio')
    @else
    <section class="mb-8 px-1">
        <div class="front-soft-hero lm-page-title px-6 py-4 text-center sm:px-8 sm:py-5">
            <nav aria-label="Breadcrumb" class="mb-2">
                <ol class="flex flex-wrap items-center justify-center gap-1.5 text-[11px] font-medium uppercase tracking-wide text-slate-500 sm:gap-2">
                    <li><a href="{{ route('home') }}" class="hover:text-slate-700">{{ __('ui.front.desktop.footer.home') }}</a></li>
                    <li class="text-slate-400">/</li>
                    <li class="text-slate-700">{{ $faqTitle }}</li>
                </ol>
            </nav>
            <h1 class="text-2xl font-extrabold uppercase tracking-tight text-slate-900">{{ $faqTitle }}</h1>
            @if ($faqPageTranslation)
                @if ($faqPageTranslation->excerpt)
                    <p class="mt-1 text-xs font-medium uppercase tracking-wide text-slate-500">{{ $faqPageTranslation->excerpt }}</p>
                @endif
            @else
                <p class="mt-1 text-xs font-medium uppercase tracking-wide text-slate-500">{{ __('ui.faq.subtitle') }}</p>
            @endif
        </div>
    </section>

    @if ($faqPageTranslation?->body_html)
        <section class="content-richtext mb-8 px-1">{!! $faqPageTranslation->body_html !!}</section>
    @endif

    <section class="bg-white px-1">
        @forelse ($faqs as $faq)
            @php
                $translation = $faq->translations->firstWhere('locale', $locale)
                    ?? $faq->translations->firstWhere('locale', $fallbackLocale)
                    ?? $faq->translations->first();
            @endphp
            @if ($translation)
                <details class="faq-accordion-item group" @if($loop->first) open @endif>
                    <summary class="faq-accordion-summary flex items-center justify-between gap-4 px-4 py-4 text-left">
                        <span class="text-xl font-semibold text-slate-900">{{ $translation->question }}</span>
                        <span class="text-slate-500 transition group-open:rotate-45 text-2xl leading-none">+</span>
                    </summary>
                    <div class="content-richtext px-4 pb-5 text-slate-700">
                        {!! $translation->answer_html ?: '<p>—</p>' !!}
                    </div>
                </details>
            @endif
        @empty
            <div class="px-4 py-8 text-slate-600">{{ __('ui.faq.empty') }}</div>
        @endforelse
    </section>
    @endif

    @if ($bottomBlocks->isNotEmpty())
        <section class="mt-10">@include('components.content-placement', ['items' => $bottomBlocks])</section>
    @endif
@endsection
