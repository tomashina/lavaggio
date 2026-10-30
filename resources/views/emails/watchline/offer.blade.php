@component('emails.watchline.layout', ['title' => $offer->title])
    <p class="eyebrow">Vaša ponuda</p>
    <h1>{{ $offer->title }}</h1>
    <p>{{ __('customer_offers.mail_greeting', ['name' => data_get($offer->customer_snapshot, 'company')]) }}</p>
    <p class="intro">{{ __('customer_offers.mail_body', ['number' => $offer->number, 'date' => $offer->valid_until->format('d.m.Y.')]) }}</p>
    @if ($offer->intro)<div class="panel pre-line">{{ $offer->intro }}</div>@endif
    <p>{{ __('customer_offers.mail_closing') }}<br>{{ \App\Support\Mail\StoreMailBrand::name() }}</p>
@endcomponent
