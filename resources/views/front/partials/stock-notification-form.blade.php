<form method="post" action="{{ route('stock-notifications.store') }}" class="stock-notify-form" data-stock-notify-form novalidate>
    @csrf
    <input type="hidden" name="product_id" value="{{ old('product_id', $stockNotifyProductId ?? '') }}" @disabled(empty(old('product_id', $stockNotifyProductId ?? '')))>
    <input type="hidden" name="bundle_id" value="{{ old('bundle_id', $stockNotifyBundleId ?? '') }}" @disabled(empty(old('bundle_id', $stockNotifyBundleId ?? '')))>
    <input type="hidden" name="product_option_value_id" value="{{ old('product_option_value_id', $stockNotifyOptionId ?? '') }}" @disabled(empty(old('product_option_value_id', $stockNotifyOptionId ?? '')))>
    <div class="stock-notify-honeypot" aria-hidden="true">
        <label for="{{ $stockNotifyFormId }}-website">Ostavite ovo polje prazno</label>
        <input type="text" id="{{ $stockNotifyFormId }}-website" name="website" value="" autocomplete="off" tabindex="-1">
    </div>
    <div class="stock-notify-field">
        <label for="{{ $stockNotifyFormId }}-email">Vaša e-mail adresa</label>
        <input type="email" id="{{ $stockNotifyFormId }}-email" name="email" value="{{ old('email', auth()->user()?->email ?? '') }}" autocomplete="email" inputmode="email" maxlength="190" placeholder="ime@primjer.hr" required aria-describedby="{{ $stockNotifyFormId }}-email-error" @error('email') aria-invalid="true" @enderror>
        <p id="{{ $stockNotifyFormId }}-email-error" class="stock-notify-field-error" data-stock-notify-error="email" @if(!$errors->has('email')) hidden @endif>{{ $errors->first('email') }}</p>
    </div>
    <div class="stock-notify-consent">
        <label for="{{ $stockNotifyFormId }}-consent">
            <input type="checkbox" id="{{ $stockNotifyFormId }}-consent" name="consent" value="1" required @checked(old('consent')) aria-describedby="{{ $stockNotifyFormId }}-consent-error">
            <span>Slažem se da mi pošaljete jednu e-mail obavijest kada odabrani artikl, varijanta ili komplet ponovno budu dostupni.</span>
        </label>
        <p id="{{ $stockNotifyFormId }}-consent-error" class="stock-notify-field-error" data-stock-notify-error="consent" @if(!$errors->has('consent')) hidden @endif>{{ $errors->first('consent') }}</p>
    </div>
    <p class="stock-notify-status" data-stock-notify-status role="status" aria-live="polite" tabindex="-1" @if(!session('success') && !$errors->any()) hidden @endif>{{ session('success') ?: ($errors->first('product_id') ?: $errors->first('bundle_id') ?: $errors->first('product_option_value_id') ?: $errors->first()) }}</p>
    <button type="submit" class="stock-notify-button stock-notify-submit">
        <x-fa-icon name="bell" class="stock-notify-button-icon" />
        <span data-stock-notify-submit-label>Obavijesti me o dostupnosti</span>
    </button>
    <p class="stock-notify-privacy">Adresa služi samo za ovu obavijest. Prijava vas ne obvezuje na kupnju.</p>
</form>
