@php
    $catalogRoots = collect($catalogItem['children'] ?? []);
    $catalogMenuId = 'lavaggio-web-shop-menu';
@endphp
<div class="lavaggio-catalog" data-lavaggio-catalog>
    @if ($catalogRoots->isNotEmpty())
        <button type="button" class="lavaggio-catalog-trigger" aria-expanded="false" aria-controls="{{ $catalogMenuId }}" data-lavaggio-catalog-trigger>
            <span class="lavaggio-catalog-icon" aria-hidden="true"><span></span><span></span><span></span></span>
            <span>{{ $catalogItem['label'] }}</span>
            <x-fa-icon inline name="chevron-down" style="regular" class="lavaggio-catalog-caret" />
        </button>
        <div id="{{ $catalogMenuId }}" class="lavaggio-catalog-menu" data-lavaggio-catalog-menu hidden>
            <div class="lavaggio-catalog-menu-heading">
                <strong>{{ $catalogItem['label'] }}</strong>
                <a href="{{ $catalogItem['url'] }}">{{ __('Prikaži sve') }} <x-fa-icon inline name="arrow-right" style="regular" /></a>
            </div>
            <div class="lavaggio-catalog-columns">
                <ul class="lavaggio-catalog-roots" aria-label="{{ __('Kategorije') }}">
                    @foreach ($catalogRoots as $root)
                        @php($rootPanelId = $catalogMenuId.'-category-'.$loop->index)
                        <li>
                            <a id="{{ $rootPanelId }}-link" href="{{ $root['url'] }}" class="lavaggio-catalog-root {{ $loop->first ? 'is-active' : '' }}" aria-controls="{{ $rootPanelId }}" aria-expanded="{{ $loop->first ? 'true' : 'false' }}" data-lavaggio-catalog-root data-panel-id="{{ $rootPanelId }}" @if (request()->url() === $root['url']) aria-current="page" @endif>
                                <span>{{ $root['label'] }}</span>
                                <x-fa-icon inline name="chevron-right" style="regular" />
                            </a>
                        </li>
                    @endforeach
                </ul>
                <div class="lavaggio-catalog-content">
                    @foreach ($catalogRoots as $root)
                        @php($rootPanelId = $catalogMenuId.'-category-'.$loop->index)
                        <section id="{{ $rootPanelId }}" class="lavaggio-catalog-category" aria-labelledby="{{ $rootPanelId }}-link" data-lavaggio-catalog-panel @if (! $loop->first) hidden @endif>
                            <a href="{{ $root['url'] }}" class="lavaggio-catalog-category-title">{{ $root['label'] }} <x-fa-icon inline name="arrow-right" style="regular" /></a>
                            @if (! empty($root['children']))
                                @include('front.partials.lavaggio-catalog-children', ['catalogChildren' => $root['children']])
                            @endif
                        </section>
                    @endforeach
                </div>
            </div>
        </div>
    @else
        <a href="{{ $catalogItem['url'] }}" class="lavaggio-catalog-trigger">
            <span class="lavaggio-catalog-icon" aria-hidden="true"><span></span><span></span><span></span></span>
            <span>{{ $catalogItem['label'] }}</span>
        </a>
    @endif
</div>

@once('lavaggio-catalog-navigation-script')
    <script defer src="{{ asset('front-theme/scripts/lavaggio-catalog-navigation.js') }}?v={{ filemtime(public_path('front-theme/scripts/lavaggio-catalog-navigation.js')) }}"></script>
@endonce
