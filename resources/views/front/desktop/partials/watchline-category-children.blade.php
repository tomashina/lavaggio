<ul class="watchline-category-children" data-watchline-category-depth="{{ $watchlineDepth }}">
    @foreach ($watchlineChildren as $child)
        <li class="watchline-category-child">
            <a href="{{ $child['url'] }}" @if (request()->url() === $child['url']) aria-current="page" @endif>{{ $child['label'] }}</a>
            @if (! empty($child['children']))
                @include('front.desktop.partials.watchline-category-children', ['watchlineChildren' => $child['children'], 'watchlineDepth' => $watchlineDepth + 1])
            @endif
        </li>
    @endforeach
</ul>
