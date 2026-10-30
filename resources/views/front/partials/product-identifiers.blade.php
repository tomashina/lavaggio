<dl class="{{ config('storefront.home') === 'lavaggio' ? 'lavaggio-product-identifiers' : 'mt-2 space-y-0.5 text-[10px] leading-tight text-slate-500 sm:text-[11px]' }}" data-product-identifiers>
    @if ($productDisplayCode !== '')
        <div class="flex flex-wrap gap-x-1"><dt>{{ config('storefront.home') === 'lavaggio' ? 'SKU' : __('Šifra') }}:</dt><dd class="break-all" data-product-code @if($updateProductSku ?? false) data-product-sku-value @endif>{{ $productDisplayCode }}</dd></div>
    @endif
    @if ($productEanCode !== '')
        <div class="flex flex-wrap gap-x-1"><dt>EAN:</dt><dd class="break-all" data-product-ean>{{ $productEanCode }}</dd></div>
    @endif
</dl>
