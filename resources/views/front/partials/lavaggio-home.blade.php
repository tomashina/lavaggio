@once('lavaggio-home-widget-styles')
    @push('styles')
        <link rel="stylesheet" href="{{ asset('front-theme/styles/lavaggio-home-widgets.css') }}?v={{ filemtime(public_path('front-theme/styles/lavaggio-home-widgets.css')) }}">
    @endpush
@endonce
@php
    $lavaggioHomeBlocks = $homeContentBlocks ?? collect();
    if ($lavaggioHomeBlocks->isEmpty() && ! request()->has('preview_block')) {
        $lavaggioHomeBlocks = app(\App\Services\Content\ContentBlockResolver::class)->forPlacement('home.content', app()->getLocale(), null, null, 'desktop');
    }
@endphp
<div class="wl-home-opening lavaggio-home" data-lavaggio-home-widgets>
    @include('components.content-placement', ['items' => $lavaggioHomeBlocks])
</div>
