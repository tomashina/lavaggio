@extends('front.desktop.layouts.store')

@section('title', 'Iskustva kupaca – '.(config('storefront.home') === 'watchline' ? 'Watch Line' : 'Liber Media'))
@section('main_class', 'mx-auto w-full max-w-7xl px-6 pt-0 pb-0')

@push('styles')
    <link rel="stylesheet" href="{{ asset('front-theme/styles/libermedia-featured-reviews.css') }}?v={{ filemtime(public_path('front-theme/styles/libermedia-featured-reviews.css')) }}">
@endpush

@section('content')
    <section class="lm-reviews-page" aria-labelledby="lm-reviews-page-title" data-libermedia-reviews-page>
        <header class="lm-reviews-page-header lm-page-title">
            <div>
                <nav aria-label="Putanja stranice">
                    <a href="{{ route('home') }}">Naslovnica</a>
                    <span aria-hidden="true">/</span>
                    <span aria-current="page">Iskustva kupaca</span>
                </nav>
                <p class="lm-home-reviews-eyebrow">ISKUSTVA KUPACA</p>
                <h1 id="lm-reviews-page-title">Što kažu naši kupci</h1>
                <p>{{ config('storefront.home') === 'watchline' ? 'Pročitajte iskustva kupaca s našim satovima, naočalama i nakitom.' : 'Stvarna iskustva s našim proizvodima, za lakši odabir igre i učenja.' }}</p>
            </div>
            @if ($reviewCount > 0)
                <div class="lm-reviews-page-summary" aria-label="Ukupne ocjene kupaca">
                    <strong data-reviews-average="{{ number_format($averageRating, 2, '.', '') }}">{{ number_format($averageRating, 1, ',', '.') }} <span>/ 5</span></strong>
                    <span>Prosječna ocjena kupaca</span>
                    <span data-reviews-total="{{ $reviewCount }}">Broj recenzija: {{ number_format($reviewCount, 0, ',', '.') }}</span>
                </div>
            @endif
        </header>

        @if ($reviews->isNotEmpty())
            <ul class="lm-reviews-page-grid">
                @foreach ($reviews as $featuredReview)
                    <li>@include('front.partials.libermedia-review-card', ['featuredReview' => $featuredReview, 'reviewShowFullBody' => true])</li>
                @endforeach
            </ul>
        @else
            <div class="lm-reviews-page-empty">
                <x-fa-icon name="comment" style="light" aria-hidden="true" />
                <h2>{{ $reviewCount > 0 ? 'Na ovoj stranici nema recenzija' : 'Još nema objavljenih recenzija' }}</h2>
                <p>{{ $reviewCount > 0 ? 'Vratite se na prvu stranicu i pročitajte iskustva naših kupaca.' : (config('storefront.home') === 'watchline' ? 'Istražite našu ponudu satova, naočala i nakita.' : 'Istražite našu ponudu i pronađite nešto za igru i učenje.') }}</p>
                <a href="{{ $reviewCount > 0 ? route('reviews.index') : route('shop.index') }}">{{ $reviewCount > 0 ? 'Prva stranica recenzija' : 'Istraži proizvode' }} <x-fa-icon name="arrow-right" /></a>
            </div>
        @endif

        @if ($reviews->hasPages())
            <div class="lm-reviews-page-pagination">{{ $reviews->links() }}</div>
        @endif
    </section>
@endsection
