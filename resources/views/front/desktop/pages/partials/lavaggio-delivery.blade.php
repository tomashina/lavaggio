<div class="lavaggio-delivery-content">
    @if (trim($deliverySections['intro_html']) !== '')
        <div class="content-richtext lavaggio-delivery-intro">{!! $deliverySections['intro_html'] !!}</div>
    @endif
    @foreach ($deliverySections['groups'] as $group)
        <section class="lavaggio-delivery-group{{ $group['cards'] === [] ? ' lavaggio-delivery-group--text' : '' }}">
            @if ($group['title_html'] !== '')
                <h2>{!! $group['title_html'] !!}</h2>
            @endif
            @if (trim($group['body_html']) !== '')
                <div class="content-richtext lavaggio-delivery-group-copy">{!! $group['body_html'] !!}</div>
            @endif
            @if ($group['cards'] !== [])
                <div class="lavaggio-delivery-cards{{ count($group['cards']) === 1 ? ' lavaggio-delivery-cards--single' : '' }}">
                    @foreach ($group['cards'] as $card)
                        <article class="lavaggio-delivery-card">
                            <h3>{!! $card['title_html'] !!}</h3>
                            <div class="content-richtext">{!! $card['body_html'] !!}</div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    @endforeach
</div>
