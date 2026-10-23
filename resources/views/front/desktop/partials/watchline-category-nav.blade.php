@php
    $watchlineCategoryItems = $navigationService->primaryCategoryItems((string) app()->getLocale());
    $watchlineNavSurface = $watchlineNavSurface ?? 'desktop';
@endphp
<div class="watchline-category-nav" data-watchline-category-nav="{{ $watchlineNavSurface }}">
    @foreach ($watchlineCategoryItems as $item)
        @php
            $children = collect($item['children'] ?? []);
            $menuId = 'watchline-'.$watchlineNavSurface.'-'.$item['key'];
        @endphp
        <div class="watchline-category-entry" data-watchline-nav-item>
            <div class="watchline-category-heading">
                <a href="{{ $item['url'] }}" class="watchline-category-link" data-watchline-category-link @if (request()->url() === $item['url']) aria-current="page" @endif>{{ $item['label'] }}</a>
                @if ($children->isNotEmpty())
                    <button type="button" class="watchline-category-toggle" aria-label="{{ __('Podkategorije: :category', ['category' => $item['label']]) }}" aria-expanded="false" aria-controls="{{ $menuId }}" data-watchline-category-toggle>
                        <x-fa-icon name="chevron-down" style="regular" :inline="true" />
                    </button>
                @endif
            </div>
            @if ($children->isNotEmpty())
                <div id="{{ $menuId }}" class="watchline-category-menu" data-watchline-nav-panel hidden>
                    <div class="watchline-category-menu-heading">
                        <strong>{{ $item['label'] }}</strong>
                        <a href="{{ $item['url'] }}">{{ __('Prikaži sve') }} <x-fa-icon name="arrow-right" :inline="true" /></a>
                    </div>
                    @include('front.desktop.partials.watchline-category-children', ['watchlineChildren' => $children, 'watchlineDepth' => 0])
                </div>
            @endif
        </div>
    @endforeach
</div>

@once('watchline-category-navigation-script')
    <script defer src="{{ asset('front-theme/scripts/watchline-category-navigation.js') }}?v={{ filemtime(public_path('front-theme/scripts/watchline-category-navigation.js')) }}"></script>
@endonce
