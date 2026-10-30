@php($problemLinks = \App\Support\LavaggioProductFilters::navigationLinks($problemCategoryUrl ?? null, $problemCategoryKey ?? null))
@if ($problemLinks !== [])
    <nav class="lavaggio-problem-links" aria-label="{{ __('Pronađite rješenje') }}">
        <span class="lavaggio-problem-links-label">{{ __('Pronađite rješenje') }}</span>
        @foreach ($problemLinks as $problemLink)
            <a href="{{ $problemLink['url'] }}" data-lavaggio-problem-link>
                <span>{{ $problemLink['label'] }}</span>
                <x-fa-icon inline name="arrow-right" style="regular" />
            </a>
        @endforeach
    </nav>
@endif
