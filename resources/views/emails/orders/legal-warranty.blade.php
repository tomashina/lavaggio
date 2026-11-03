@if (($variant ?? '') === 'customer')
    @if (($legal_warranty['enabled'] ?? false) && ($legal_warranty['email_enabled'] ?? true))
        <div style="margin-top:20px;padding:16px;border:2px solid {{ \App\Support\Mail\StoreMailBrand::isLavaggio() ? '#848ac4' : '#00b5cc' }};background:#f7f7f7;color:#32373b">
            <h2 style="margin:0 0 12px;font-size:18px">{{ __('herrera.warranty.title') }}</h2>
            @if (\App\Support\Mail\StoreMailBrand::isLavaggio())
                <p style="margin:0 0 10px">Za materijalne nedostatke robe odgovaramo najmanje dvije godine od predaje robe, u skladu sa zakonom. Ta prava ostvarujete kod prodavatelja, neovisno o dodatnom komercijalnom jamstvu.</p>
            @endif
            <p style="margin:0 0 10px"><a href="{{ $legal_warranty['asset_url'] }}">{{ __('herrera.warranty.view_notice') }}</a></p>
            <p style="margin:0"><a href="{{ $legal_warranty['eu_url'] }}">{{ __('herrera.warranty.eu_information') }}</a></p>
        </div>
    @endif
    @if (\App\Support\Mail\StoreMailBrand::isLavaggio())
        <p style="margin:20px 0 0;font-size:14px;line-height:1.6">Za jednostrani raskid ugovora u zakonskom roku od 14 dana od primitka robe koristite <a href="{{ route('returns.create', ['returnRequestSlug' => __('return_request.slug')]) }}">jednostavan obrazac za raskid ugovora</a>. Razlog nije potrebno navesti.</p>
    @endif
@endif
