@php
    $catalogRoots = collect($catalogItem['children'] ?? [])->values();
    $firstPanelIndex = $catalogRoots->search(fn (array $root): bool => ! empty($root['children']));
    $isShopMenu = ($catalogItem['type'] ?? 'catalog') === 'catalog';
    $catalogMenuKey = (string) ($catalogItem['key'] ?? (($catalogItem['label'] ?? 'navigation').'-'.substr(hash('sha256', (string) ($catalogItem['url'] ?? '')), 0, 10)));
    $catalogMenuId = $isShopMenu ? 'lavaggio-web-shop-menu' : 'lavaggio-navigation-'.\Illuminate\Support\Str::slug($catalogMenuKey);
@endphp
<div class="lavaggio-catalog" data-lavaggio-catalog>
    @if ($catalogRoots->isNotEmpty())
        <button type="button" class="lavaggio-catalog-trigger" aria-expanded="false" aria-controls="{{ $catalogMenuId }}" data-lavaggio-catalog-trigger>
            @if ($isShopMenu)<span class="lavaggio-catalog-icon" aria-hidden="true"><span></span><span></span><span></span></span>@endif
            <span>{{ $catalogItem['label'] }}</span>
            <x-fa-icon inline name="chevron-down" style="regular" class="lavaggio-catalog-caret" />
        </button>
        <div id="{{ $catalogMenuId }}" class="lavaggio-catalog-menu" data-lavaggio-catalog-menu hidden>
            <div class="lavaggio-catalog-menu-heading">
                <strong>{{ $catalogItem['label'] }}</strong>
                <a href="{{ $catalogItem['url'] }}">{{ __('Prikaži sve') }} <x-fa-icon inline name="arrow-right" style="regular" /></a>
            </div>
            <div class="lavaggio-catalog-columns {{ $firstPanelIndex === false ? 'has-direct-links-only' : '' }}">
                <ul class="lavaggio-catalog-roots" aria-label="{{ $isShopMenu ? __('Kategorije') : $catalogItem['label'] }}">
                    @foreach ($catalogRoots as $root)
                        @php
                            $rootPanelId = $catalogMenuId.'-category-'.$loop->index;
                            $rootHasChildren = ! empty($root['children']);
                        @endphp
                        <li>
                            <a id="{{ $rootPanelId }}-link" href="{{ $root['url'] }}" class="lavaggio-catalog-root {{ $rootHasChildren && $loop->index === $firstPanelIndex ? 'is-active' : '' }}" data-lavaggio-catalog-root @if ($rootHasChildren) aria-controls="{{ $rootPanelId }}" aria-expanded="{{ $loop->index === $firstPanelIndex ? 'true' : 'false' }}" data-panel-id="{{ $rootPanelId }}" @endif @if (request()->url() === $root['url']) aria-current="page" @endif>
                                <span>{{ $root['label'] }}</span>
                                @if ($rootHasChildren)<x-fa-icon inline name="chevron-right" style="regular" />@endif
                            </a>
                        </li>
                    @endforeach
                </ul>
                @if ($firstPanelIndex !== false)
                <div class="lavaggio-catalog-content">
                    @foreach ($catalogRoots as $root)
                        @continue(empty($root['children']))
                        @php
                            $rootPanelId = $catalogMenuId.'-category-'.$loop->index;
                            $rootImageUrl = trim((string) ($root['menu_image_url'] ?? ''));
                            $rootImageAlt = trim((string) ($root['menu_image_alt'] ?? '')) ?: $root['label'];
                        @endphp
                        <section id="{{ $rootPanelId }}" class="lavaggio-catalog-category {{ $rootImageUrl !== '' ? 'has-image' : '' }}" aria-labelledby="{{ $rootPanelId }}-link" data-lavaggio-catalog-panel @if ($loop->index !== $firstPanelIndex) hidden @endif>
                            <div class="lavaggio-catalog-category-copy">
                                <a href="{{ $root['url'] }}" class="lavaggio-catalog-category-title">{{ $root['label'] }} <x-fa-icon inline name="arrow-right" style="regular" /></a>
                                @if (! empty($root['children']))
                                    @include('front.partials.lavaggio-catalog-children', ['catalogChildren' => $root['children']])
                                @endif
                                @if ($isShopMenu)
                                    @include('front.partials.lavaggio-problem-links', ['problemCategoryUrl' => $root['url'], 'problemCategoryKey' => $root['key'] ?? null])
                                @endif
                            </div>
                            @if ($rootImageUrl !== '')
                                <div class="lavaggio-catalog-category-image">
                                    <img src="{{ $rootImageUrl }}" alt="{{ $rootImageAlt }}" width="800" height="600" loading="lazy" decoding="async">
                                </div>
                            @endif
                        </section>
                    @endforeach
                </div>
                @endif
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
    <link rel="stylesheet" href="{{ asset('front-theme/styles/lavaggio-problems.css') }}?v={{ filemtime(public_path('front-theme/styles/lavaggio-problems.css')) }}">
    <script defer src="{{ asset('front-theme/scripts/lavaggio-catalog-navigation.js') }}?v={{ filemtime(public_path('front-theme/scripts/lavaggio-catalog-navigation.js')) }}"></script>
@endonce
