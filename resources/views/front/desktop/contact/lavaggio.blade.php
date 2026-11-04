@php
    $contactPrivacyUrl = route('pages.show', 'zastita-podataka-i-prigovor');
    $contactCards = app(\App\Services\Content\LavaggioContactCards::class)->forStorefront($storeSettings['footer'] ?? []);
    $contactSocials = \App\Support\StorefrontSocialLinks::resolve($storeSettings['branding']['social'] ?? [], [], true);
@endphp

<section class="lm-page-title lavaggio-contact-title">
    <nav aria-label="Breadcrumb">
        <a href="{{ route('home') }}"><x-front.breadcrumb-home>Početna</x-front.breadcrumb-home></a><span aria-hidden="true">/</span><span>Kontakt</span>
    </nav>
    <h1>Kontakt</h1>
</section>

<section class="lavaggio-contact-cards" aria-label="Kontakt podaci">
    @foreach ($contactCards as $card)
        <article class="lavaggio-contact-card" data-lavaggio-contact-card>
            <span class="lavaggio-contact-card-icon" aria-hidden="true"><x-fa-icon :name="$card['icon']" /></span>
            <h2>{{ $card['title'] }}</h2>
            @if ($card['text'] !== '')<p>{{ $card['text'] }}</p>@endif
            @if ($card['href'] !== '' && $card['link_label'] !== '')
                <a class="lavaggio-contact-card-link" href="{{ $card['href'] }}">{{ $card['link_label'] }} <span aria-hidden="true">→</span></a>
            @endif
            @if ($card['hours'] !== '')<p class="lavaggio-contact-card-hours lavaggio-contact-hours"><x-fa-icon name="clock" inline class="lavaggio-contact-hours-icon" /><span class="sr-only">Radno vrijeme: </span>{{ $card['hours'] }}</p>@endif
        </article>
    @endforeach
</section>

<section class="lavaggio-contact-layout" aria-label="Pošaljite upit">
    <form
        method="POST"
        action="{{ route('contact.store') }}"
        class="lavaggio-contact-form"
        novalidate
        data-contact-form
        data-msg-name-required="{{ __('contact.validation.inline.name_required') }}"
        data-msg-email-required="{{ __('contact.validation.inline.email_required') }}"
        data-msg-email-invalid="{{ __('contact.validation.inline.email_invalid') }}"
        data-msg-message-required="{{ __('contact.validation.inline.message_required') }}"
        data-msg-message-min="{{ __('contact.validation.inline.message_min') }}"
        data-msg-accept-terms="{{ __('contact.validation.inline.accept_terms') }}"
        @if($captchaEnabled) data-recaptcha-form data-recaptcha-site-key="{{ $captchaSiteKey }}" data-recaptcha-action="contact_form" @endif
    >
        <h2>Kako vam možemo pomoći?</h2>
        @csrf
        <input type="hidden" name="recaptcha_token" value="" data-recaptcha-token>

        @if (session('status'))
            <p class="lavaggio-contact-success" role="status">{{ session('status') }}</p>
        @endif

        <div class="lavaggio-contact-field">
            <label for="contact-name">Vaše ime i prezime <x-front.required-mark /></label>
            <input type="text" id="contact-name" name="name" value="{{ old('name', auth()->user()?->name) }}" placeholder="Da znamo s kim imamo čast!" autocomplete="name" required>
            <p class="lavaggio-contact-error {{ $errors->has('name') ? '' : 'hidden' }}" data-field-error="name">@error('name'){{ $message }}@enderror</p>
        </div>

        <div class="lavaggio-contact-field">
            <label for="contact-email">Email adresa <x-front.required-mark /></label>
            <input type="email" id="contact-email" name="email" value="{{ old('email', auth()->user()?->email) }}" placeholder="Kako vas možemo kontaktirati nazad?" autocomplete="email" required>
            <p class="lavaggio-contact-error {{ $errors->has('email') ? '' : 'hidden' }}" data-field-error="email">@error('email'){{ $message }}@enderror</p>
        </div>

        <div class="lavaggio-contact-field">
            <label for="contact-message">Poruka / Detalji <x-front.required-mark /></label>
            <textarea id="contact-message" name="message" rows="4" placeholder="Napišite što vam treba, a mi ćemo se brzo javiti." required>{{ old('message') }}</textarea>
            <p class="lavaggio-contact-error {{ $errors->has('message') ? '' : 'hidden' }}" data-field-error="message">@error('message'){{ $message }}@enderror</p>
        </div>

        <div class="lavaggio-contact-field">
            <label class="lavaggio-contact-consent" for="contact-terms">
                <input type="checkbox" id="contact-terms" name="accept_terms" value="1" required @checked((bool) old('accept_terms'))>
                <span>Slažem se sa <a href="{{ $contactPrivacyUrl }}">uvjetima korištenja</a> <x-front.required-mark /></span>
            </label>
            <p class="lavaggio-contact-error {{ $errors->has('accept_terms') ? '' : 'hidden' }}" data-field-error="accept_terms">@error('accept_terms'){{ $message }}@enderror</p>
        </div>

        <button type="submit" class="lavaggio-contact-submit">Pošaljite upit</button>
        <p class="lavaggio-contact-error {{ $errors->has('recaptcha_token') ? '' : 'hidden' }}" data-field-error="recaptcha_token">@error('recaptcha_token'){{ $message }}@enderror</p>
    </form>
</section>
@if ($contactSocials->isNotEmpty())
    <nav class="lavaggio-contact-socials" aria-label="Društvene mreže">
        @foreach ($contactSocials as $social)
            <a href="{{ $social['url'] }}" aria-label="{{ $social['label'] }}"><x-fa-icon :name="$social['network']" style="brands" /></a>
        @endforeach
    </nav>
@endif
