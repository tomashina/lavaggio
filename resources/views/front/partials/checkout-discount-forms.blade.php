{{-- Detached forms keep voucher and coupon requests out of the order form. --}}
@if (config('gift_vouchers.enabled', true))
    <form id="gift-voucher-checkout-apply" method="POST" action="{{ route('cart.gift-voucher.apply') }}" data-gift-voucher-apply>@csrf<input type="hidden" name="return_to" value="checkout"></form>
    <form id="gift-voucher-checkout-remove" method="POST" action="{{ route('cart.gift-voucher.remove') }}" data-gift-voucher-remove>@csrf @method('DELETE')<input type="hidden" name="return_to" value="checkout"></form>
@endif
<form id="checkout-coupon-apply" method="POST" action="{{ route('cart.coupon.apply') }}">@csrf<input type="hidden" name="return_to" value="checkout"></form>
<form id="checkout-coupon-remove" method="POST" action="{{ route('cart.coupon.remove') }}">@csrf @method('DELETE')<input type="hidden" name="return_to" value="checkout"></form>
