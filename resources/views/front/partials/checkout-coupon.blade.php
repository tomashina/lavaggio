@php($checkoutCouponCode = (string) ($summary['coupon_code'] ?? ''))
<details class="gift-voucher-redemption" data-checkout-coupon @if($checkoutCouponCode !== '' || $errors->has('coupon_code')) open @endif>
    <summary><x-fa-icon name="tag" /><span>Imate kod za popust?</span><x-fa-icon name="chevron-down" /></summary>
    <div class="gift-voucher-redemption-content">
        <p>Unesite kod za popust na narudžbu.</p>
        <label for="checkout-coupon-code">Kod za popust <span class="store-form-required" aria-hidden="true">*</span></label>
        <div class="gift-voucher-code-row">
            <input id="checkout-coupon-code" name="coupon_code" form="checkout-coupon-apply" value="{{ old('coupon_code', $checkoutCouponCode) }}" type="text" maxlength="60" autocomplete="off" autocapitalize="characters" spellcheck="false" required>
            <button type="submit" form="checkout-coupon-apply" class="gift-voucher-apply-button">Primijeni</button>
        </div>
        <p class="gift-voucher-redemption-status {{ $errors->has('coupon_code') ? 'is-error' : '' }}" data-checkout-coupon-status role="status" aria-live="polite" @if($checkoutCouponCode === '' && !$errors->has('coupon_code')) hidden @endif>{{ $errors->first('coupon_code') ?: 'Kod za popust je primijenjen.' }}</p>
        <button type="submit" form="checkout-coupon-remove" class="gift-voucher-remove-button" data-checkout-coupon-remove @if($checkoutCouponCode === '') hidden @endif>Ukloni kod za popust</button>
    </div>
</details>
