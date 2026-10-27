<ul class="lavaggio-catalog-children">
    @foreach ($catalogChildren as $child)
        <li>
            <a href="{{ $child['url'] }}" @if (request()->url() === $child['url']) aria-current="page" @endif>{{ $child['label'] }}</a>
            @if (! empty($child['children']))
                @include('front.partials.lavaggio-catalog-children', ['catalogChildren' => $child['children']])
            @endif
        </li>
    @endforeach
</ul>
