@php
    $contactEmail = trim((string) ($storeSettings['footer']['email_support'] ?? '')) ?: 'info@lavaggio.hr';
    $contactPhone = trim((string) ($storeSettings['footer']['phone'] ?? '')) ?: '097 799 44 11';
    $contactPhoneHref = preg_replace('/\D+/', '', $contactPhone);
    if (str_starts_with($contactPhoneHref, '0')) {
        $contactPhoneHref = '385'.substr($contactPhoneHref, 1);
    }
    $contactPrivacyUrl = route('pages.show', 'zastita-podataka-i-prigovor');
    $contactSocials = $storeSettings['branding']['social'] ?? [];
@endphp

<section class="lm-page-title lavaggio-contact-title">
    <nav aria-label="Breadcrumb">
        <a href="{{ route('home') }}">Početna</a><span aria-hidden="true">/</span><span>Kontakt</span>
    </nav>
    <h1>Kontakt</h1>
</section>

<section class="lavaggio-contact-layout" aria-label="Kontakt podaci i upit">
    <div class="lavaggio-contact-details">
        <h2>LAVAGGIO®</h2>
        <p>Samoposlužna i uslužna praonica rublja - Barban</p>

        <dl class="lavaggio-contact-methods">
            <div>
                <x-fa-icon name="location-dot" />
                <dt>Adresa:</dt>
                <dd>Barban 70, 52207, Barban</dd>
            </div>
            <div>
                <x-fa-icon name="phone" />
                <dt>Telefon:</dt>
                <dd><a href="tel:+{{ $contactPhoneHref }}">{{ $contactPhone === '097 799 44 11' ? '+385 (0)97 799 44 11' : $contactPhone }}</a></dd>
            </div>
            <div>
                <x-fa-icon name="envelope" />
                <dt>E-mail:</dt>
                <dd><a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a></dd>
            </div>
        </dl>

        <nav class="lavaggio-contact-socials" aria-label="Društvene mreže">
            @foreach (['tiktok' => 'TikTok', 'instagram' => 'Instagram', 'youtube' => 'YouTube', 'facebook' => 'Facebook'] as $network => $label)
                @if (! empty($contactSocials[$network]['url']) && ($contactSocials[$network]['enabled'] ?? false))
                    <a href="{{ $contactSocials[$network]['url'] }}" aria-label="{{ $label }}"><x-fa-icon :name="$network" style="brands" /></a>
                @endif
            @endforeach
            <a href="https://www.linkedin.com/company/lavaggio-laundry/" aria-label="LinkedIn"><x-fa-icon name="linkedin" style="brands" /></a>
        </nav>
    </div>

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
        <h2>Trebate čisto rublje bez muke? Javite nam se!</h2>
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

<section class="lavaggio-contact-map" aria-label="Lokacija Lavaggio praonice">
    <iframe title="Lavaggio praonica rublja, Barban 70" src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2818.0576747005507!2d14.0162747!3d45.06434110000001!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x47634b05a642fa9d%3A0x8b9a87ce7d05f5bd!2sLAVAGGIO%20-%20Samoposlu%C5%BEna%20i%20uslu%C5%BEna%20praonica%20rublja!5e0!3m2!1shr!2shr!4v1750271673507!5m2!1shr!2shr" width="1320" height="550" allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
</section>
