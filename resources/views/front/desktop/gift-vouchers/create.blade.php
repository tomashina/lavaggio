@extends('front.desktop.layouts.store')

@php
    $isWatchlineVoucher = config('storefront.home') === 'watchline';
    $giftVoucherDefaultMessage = $isWatchlineVoucher ? 'Poklonite detalj koji će se pamtiti.' : 'Poklonite trenutke pune igre i veselja.';
@endphp
@section('title', $isWatchlineVoucher ? 'Poklon bon – Watchline' : 'Poklon bon – darujte igru i veselje')
@section('main_class', 'gift-voucher-shell')

@push('styles')
    <link rel="stylesheet" href="{{ asset('front-theme/styles/gift-vouchers.css') }}?v={{ filemtime(public_path('front-theme/styles/gift-vouchers.css')) }}">
@endpush

@section('content')
    @php
        $selectedPayment = (string) old('payment_method_code', $paymentMethods->first()?->code ?? '');
        $initialPayment = $paymentOptions->firstWhere('code', $selectedPayment) ?? $paymentOptions->first();
    @endphp
    <header class="gift-voucher-heading lm-page-title">
        <p class="gift-voucher-eyebrow"><x-fa-icon name="gift" /> {{ $isWatchlineVoucher ? 'POKLON PO NJIHOVOM IZBORU' : 'POKLON ZA MALE VELIKE IDEJE' }}</p>
        <h1>{{ $isWatchlineVoucher ? 'Darujte izbor. Poklonite Watchline.' : 'Darujte igru. Prepustite im izbor.' }}</h1>
        <p>{{ $isWatchlineVoucher ? 'Poklon bon Watchline za satove, naočale i nakit. Prepustite odabir onome kome darujete.' : 'Za rođendan, mali uspjeh ili samo zato. Poklon bon Liber Media otvara svijet igre, mašte i učenja.' }}</p>
        <div class="gift-voucher-benefits"><span><x-fa-icon name="envelope" /> Dostava e-mailom nakon uplate</span><span><x-fa-icon name="gift" /> Iznosi od 10 do 300 €</span></div>
    </header>

    @if ($errors->any())
        <div class="gift-voucher-error-summary" role="alert" tabindex="-1">
            <strong>Provjerite označena polja.</strong>
            <ul>@foreach ($errors->messages() as $field => $messages)<li><a href="#gift-{{ str_replace('_', '-', $field) }}">{{ $messages[0] }}</a></li>@endforeach</ul>
        </div>
    @endif

    <div class="gift-voucher-layout">
        <form action="{{ route('gift-vouchers.store') }}" method="POST" class="gift-voucher-form" data-gift-purchase-form data-gift-default-message="{{ $giftVoucherDefaultMessage }}" data-payment-options-url="{{ route('gift-vouchers.create') }}">
            @csrf
            <section class="gift-voucher-form-card">
                <h2><span>1</span> Odaberite iznos</h2>
                <div class="gift-voucher-amount-presets" aria-label="Brzi odabir iznosa">
                    @foreach ([20, 30, 50, 100, 150, 200] as $preset)
                        @if ($amounts->contains($preset))
                            <button type="button" data-gift-amount-preset="{{ $preset }}" aria-pressed="{{ $selectedAmount === $preset ? 'true' : 'false' }}">{{ $preset }} €</button>
                        @endif
                    @endforeach
                </div>
                <div class="gift-voucher-field">
                    <label for="gift-amount">Iznos poklon bona <x-front.required-mark /></label>
                    <select id="gift-amount" name="amount" required data-gift-amount @error('amount') aria-invalid="true" aria-describedby="gift-amount-error" @enderror>
                        @foreach ($amounts as $amount)<option value="{{ $amount }}" @selected($selectedAmount === (int) $amount)>{{ $amount }} €</option>@endforeach
                    </select>
                    <small>Odaberite bilo koji iznos od 10 do 300 €, u koracima od 10 €.</small>
                    @error('amount')<p id="gift-amount-error" class="gift-voucher-field-error">{{ $message }}</p>@enderror
                </div>
            </section>

            <section class="gift-voucher-form-card">
                <h2><span>2</span> Za koga pripremamo poklon?</h2>
                <div class="gift-voucher-field-grid">
                    <div class="gift-voucher-field">
                        <label for="gift-recipient-name">Ime primatelja <x-front.required-mark /></label>
                        <input id="gift-recipient-name" name="recipient_name" type="text" maxlength="191" value="{{ old('recipient_name') }}" placeholder="Kome darujete bon?" autocomplete="off" required data-gift-recipient @error('recipient_name') aria-invalid="true" aria-describedby="gift-recipient-name-error" @enderror>
                        @error('recipient_name')<p id="gift-recipient-name-error" class="gift-voucher-field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="gift-voucher-field">
                        <label for="gift-recipient-email">E-mail primatelja <x-front.required-mark /></label>
                        <input id="gift-recipient-email" name="recipient_email" type="email" maxlength="191" value="{{ old('recipient_email') }}" autocomplete="off" placeholder="primatelj@primjer.hr" required @error('recipient_email') aria-invalid="true" aria-describedby="gift-recipient-email-error" @enderror>
                        @error('recipient_email')<p id="gift-recipient-email-error" class="gift-voucher-field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="gift-voucher-field gift-voucher-field-wide">
                        <label for="gift-sender-name">Ime pošiljatelja <x-front.required-mark /></label>
                        <input id="gift-sender-name" name="sender_name" type="text" maxlength="191" value="{{ old('sender_name', $defaults['sender_name']) }}" placeholder="Kako se potpisujete?" required data-gift-sender @error('sender_name') aria-invalid="true" aria-describedby="gift-sender-name-error" @enderror>
                        @error('sender_name')<p id="gift-sender-name-error" class="gift-voucher-field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="gift-voucher-field gift-voucher-field-wide">
                        <label for="gift-message">Vaša poruka <small>neobavezno</small></label>
                        <textarea id="gift-message" name="message" rows="4" maxlength="1000" placeholder="Dodajte nekoliko lijepih riječi…" data-gift-message @error('message') aria-invalid="true" aria-describedby="gift-message-error" @enderror>{{ old('message') }}</textarea>
                        <small>Poruka će biti prikazana uz poklon bon.</small>
                        @error('message')<p id="gift-message-error" class="gift-voucher-field-error">{{ $message }}</p>@enderror
                    </div>
                </div>
            </section>

            <section class="gift-voucher-form-card">
                <h2><span>3</span> Vaši podaci za račun</h2>
                <p>Potvrdu narudžbe šaljemo na vaš e-mail. Digitalni bon šaljemo na e-mail primatelja nakon potvrde uplate.</p>
                <div class="gift-voucher-field-grid">
                    @foreach ([
                        ['customer_first_name', 'Ime', 'given-name', 'text'],
                        ['customer_last_name', 'Prezime', 'family-name', 'text'],
                        ['customer_email', 'Vaš e-mail', 'email', 'email'],
                        ['customer_phone', 'Telefon', 'tel', 'tel'],
                        ['billing_address_line_1', 'Ulica i kućni broj', 'street-address', 'text'],
                        ['billing_postal_code', 'Poštanski broj', 'postal-code', 'text'],
                        ['billing_city', 'Grad / mjesto', 'address-level2', 'text'],
                    ] as [$name, $label, $autocomplete, $type])
                        <div class="gift-voucher-field {{ $name === 'billing_address_line_1' ? 'gift-voucher-field-wide' : '' }}">
                            <label for="gift-{{ str_replace('_', '-', $name) }}">{{ $label }} <x-front.required-mark /></label>
                            <input id="gift-{{ str_replace('_', '-', $name) }}" name="{{ $name }}" type="{{ $type }}" autocomplete="billing {{ $autocomplete }}" value="{{ old($name, $defaults[$name] ?? '') }}" maxlength="{{ $name === 'customer_phone' ? 25 : ($name === 'billing_postal_code' ? 32 : (in_array($name, ['customer_first_name', 'customer_last_name', 'billing_city'], true) ? 120 : 191)) }}" required @error($name) aria-invalid="true" aria-describedby="gift-{{ str_replace('_', '-', $name) }}-error" @enderror>
                            @error($name)<p id="gift-{{ str_replace('_', '-', $name) }}-error" class="gift-voucher-field-error">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                    <div class="gift-voucher-field">
                        <label for="gift-billing-country-code">Država <x-front.required-mark /></label>
                        <select id="gift-billing-country-code" name="billing_country_code" autocomplete="billing country" required @error('billing_country_code') aria-invalid="true" aria-describedby="gift-billing-country-code-error" @enderror>
                            @foreach ($countryOptions as $country)<option value="{{ $country['code'] }}" @selected(old('billing_country_code', $defaults['billing_country_code']) === $country['code'])>{{ $country['label'] }}</option>@endforeach
                        </select>
                        @error('billing_country_code')<p id="gift-billing-country-code-error" class="gift-voucher-field-error">{{ $message }}</p>@enderror
                    </div>
                </div>
            </section>

            <section class="gift-voucher-form-card">
                <h2 id="gift-payment-method-code"><span>4</span> Način plaćanja</h2>
                @if ($isWatchlineVoucher)<p>Poklon bon moguće je platiti isključivo karticom.</p>@endif
                <div class="gift-voucher-payment-options" data-gift-payment-options>
                    @forelse ($paymentMethods as $method)
                        <label class="gift-voucher-payment-option">
                            <input type="radio" name="payment_method_code" value="{{ $method->code }}" @checked($selectedPayment === (string) $method->code) required data-gift-method-fee="{{ $paymentOptions->firstWhere('code', (string) $method->code)['payment_fee_total'] }}" data-gift-method-fee-formatted="{{ $paymentOptions->firstWhere('code', (string) $method->code)['payment_fee_total_formatted'] }}" data-gift-method-total-formatted="{{ $paymentOptions->firstWhere('code', (string) $method->code)['grand_total_formatted'] }}">
                            <span class="gift-voucher-payment-copy"><strong>{{ $method->name }}</strong>@if($method->description)<small>{{ $method->description }}</small>@endif</span>
                        </label>
                    @empty
                        <p class="gift-voucher-field-error">Kupnja poklon bona trenutačno nije dostupna. Pokušajte ponovno kasnije.</p>
                    @endforelse
                </div>
                @error('payment_method_code')<p class="gift-voucher-field-error">{{ $message }}</p>@enderror
                <p class="gift-voucher-field-error" data-gift-payment-error hidden role="alert"></p>
                <div class="gift-voucher-purchase-total" aria-live="polite">
                    <div><span>Vrijednost poklon bona</span><strong data-gift-total-amount>{{ \App\Support\Currency::format($selectedAmount) }}</strong></div>
                    <div data-gift-payment-fee-row @if((float) ($initialPayment['payment_fee_total'] ?? 0) <= 0) hidden @endif><span>Naknada plaćanja</span><strong data-gift-payment-fee-value>{{ (float) ($initialPayment['payment_fee_total'] ?? 0) > 0 ? $initialPayment['payment_fee_total_formatted'] : '' }}</strong></div>
                    <div><span>Ukupno za uplatu</span><strong data-gift-grand-total>{{ $initialPayment['grand_total_formatted'] ?? \App\Support\Currency::format($selectedAmount) }}</strong></div>
                </div>
                <label class="gift-voucher-consent" id="gift-accept-terms"><input type="checkbox" name="accept_terms" value="1" required @checked(old('accept_terms'))><span>Prihvaćam @if(!empty($storeSettings['legal']['terms']['url']))<a href="{{ $storeSettings['legal']['terms']['url'] }}" target="_blank" rel="noopener">uvjete kupnje</a>@else uvjete kupnje @endif i potvrđujem da sam provjerio/la e-mail primatelja.</span></label>
                @error('accept_terms')<p class="gift-voucher-field-error">{{ $message }}</p>@enderror
                <button type="submit" class="commerce-primary-action gift-voucher-purchase-submit" data-gift-submit @disabled($paymentMethods->isEmpty())><x-fa-icon name="bag-shopping" /> Kupi poklon bon</button>
                <p class="gift-voucher-payment-note">Bez troška dostave. Bon aktiviramo i šaljemo tek nakon potvrde plaćanja.</p>
            </section>
            <section class="gift-voucher-how">
                <h2>Mali poklon, puno mogućnosti.</h2>
                <ol><li>Odaberite iznos i dodajte osobnu poruku.</li><li>Potvrdite narudžbu i izvršite uplatu.</li><li>Primatelj dobiva digitalni bon e-mailom.</li></ol>
                <p>Kod se unosi u košarici ili na blagajni. Neiskorišteni dio ostaje na bonu za sljedeću kupnju.</p>
            </section>
        </form>

        <aside class="gift-voucher-preview-column">
        <div class="gift-voucher-preview-panel" data-gift-sticky-preview>
            <p class="gift-voucher-preview-label">OVAKO IZGLEDA VAŠ POKLON</p>
            <div class="gift-voucher-preview">
                <div class="gift-voucher-preview-top"><img src="{{ asset($isWatchlineVoucher ? 'assets/brand/watchline-logo-on-dark.svg' : 'assets/brand/libermedia-logo.svg') }}" alt="{{ $isWatchlineVoucher ? 'Watchline' : 'Liber Media' }}" width="180" height="35"><span>POKLON BON</span></div>
                <x-fa-icon name="gift" class="gift-voucher-preview-gift" />
                <div class="gift-voucher-preview-amount"><span data-gift-preview-amount>{{ $selectedAmount }}</span> <small>€</small></div>
                <p>Za <strong data-gift-preview-recipient>{{ old('recipient_name') ?: 'nekoga posebnog' }}</strong></p>
                <p class="gift-voucher-preview-message" data-gift-preview-message>{{ old('message') ?: $giftVoucherDefaultMessage }}</p>
                <p class="gift-voucher-preview-sender">Od <span data-gift-preview-sender>{{ old('sender_name', $defaults['sender_name']) ?: 'vas, od srca' }}</span></p>
                <span class="gift-voucher-preview-code">VAŠ JEDINSTVENI KOD</span>
            </div>
        </div>
        </aside>
    </div>
@endsection

@push('scripts')
    <script defer src="{{ asset('front-theme/scripts/gift-vouchers.js') }}?v={{ filemtime(public_path('front-theme/scripts/gift-vouchers.js')) }}"></script>
@endpush
