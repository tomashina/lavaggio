@if (($variant ?? '') === 'customer' && ! \App\Support\Mail\StoreMailBrand::isLavaggio())
    @if (($legal_warranty['enabled'] ?? false) && ($legal_warranty['email_enabled'] ?? true))
        <div style="margin-top:20px;padding:16px;border:2px solid #00b5cc;background:#f7f7f7;color:#32373b">
            <h2 style="margin:0 0 12px;font-size:18px">{{ __('herrera.warranty.title') }}</h2>
            <p style="margin:0 0 10px"><a href="{{ $legal_warranty['asset_url'] }}">{{ __('herrera.warranty.view_notice') }}</a></p>
            <p style="margin:0"><a href="{{ $legal_warranty['eu_url'] }}">{{ __('herrera.warranty.eu_information') }}</a></p>
        </div>
    @endif
@endif
