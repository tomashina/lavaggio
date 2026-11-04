@php
    $problemSettings = \App\Support\WatchlineWidgetSettings::forBlock($block, $translation);
    $problemCards = \App\Support\LavaggioHomeProblems::cards($problemSettings);
@endphp
@if ($problemCards !== [])
    <section class="lavaggio-home-problems" aria-labelledby="lavaggio-problems-{{ $block->id }}" data-lavaggio-problems>
        <header class="lavaggio-widget-heading">
            <div>
                @if(filled($problemSettings['eyebrow']))<p class="lavaggio-widget-eyebrow">{{ $problemSettings['eyebrow'] }}</p>@endif
                <h2 id="lavaggio-problems-{{ $block->id }}">{{ $problemSettings['title'] }}</h2>
                @if(filled($problemSettings['subtitle']))<p class="lavaggio-widget-description">{{ $problemSettings['subtitle'] }}</p>@endif
            </div>
        </header>
        <div class="lavaggio-home-problem-grid">
            @foreach ($problemCards as $problemCard)
                <a href="{{ \App\Support\Lavaggio\PublicUrls::url($problemCard['url']) }}" class="lavaggio-home-problem-card">
                    <span class="lavaggio-home-problem-icon"><x-fa-icon :name="$problemCard['icon']" /></span>
                    <span>{{ $problemCard['title'] }}</span>
                    <x-fa-icon name="arrow-up-right" class="lavaggio-home-problem-arrow" />
                </a>
            @endforeach
        </div>
    </section>
@endif
