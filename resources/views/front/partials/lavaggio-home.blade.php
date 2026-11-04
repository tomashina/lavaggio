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
    $lavaggioHomeBlocks = $lavaggioHomeBlocks->values();
@endphp
<div class="wl-home-opening lavaggio-home" data-lavaggio-home-widgets>
    @for ($homeBlockIndex = 0; $homeBlockIndex < $lavaggioHomeBlocks->count(); $homeBlockIndex++)
        @php
            $homeBlock = $lavaggioHomeBlocks[$homeBlockIndex];
            $nextHomeBlock = $lavaggioHomeBlocks->get($homeBlockIndex + 1);
            $pairOpeningBlocks = $homeBlock['block']->type === 'lavaggio_campaigns'
                && ($nextHomeBlock['block']->type ?? '') === 'lavaggio_problems'
                && $homeBlock['block']->is_active && $homeBlock['slot']->is_active
                && $nextHomeBlock['block']->is_active && $nextHomeBlock['slot']->is_active;
            if ($pairOpeningBlocks) {
                $openingPromoSettings = \App\Support\WatchlineWidgetSettings::forBlock($homeBlock['block'], $homeBlock['translation']);
                $openingProblemSettings = \App\Support\WatchlineWidgetSettings::forBlock($nextHomeBlock['block'], $nextHomeBlock['translation']);
                $pairOpeningBlocks = \App\Support\LavaggioCampaignSlides::visible($openingPromoSettings) !== []
                    && collect(range(1, 6))->contains(fn ($card) => $openingProblemSettings['card_'.$card.'_enabled']
                        && filled($openingProblemSettings['card_'.$card.'_title']));
            }
        @endphp
        @if ($pairOpeningBlocks)
            <div class="lavaggio-home-intro" data-lavaggio-home-intro>
                @include('components.content-placement', ['items' => collect([$homeBlock, $nextHomeBlock])])
            </div>
            @php($homeBlockIndex++)
        @else
            @include('components.content-placement', ['items' => collect([$homeBlock])])
        @endif
    @endfor
</div>
