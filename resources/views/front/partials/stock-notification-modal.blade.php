<dialog class="stock-notify-dialog" id="stock-notification-dialog" data-stock-notify-dialog aria-labelledby="stock-notify-title" aria-describedby="stock-notify-description">
    <div class="stock-notify-dialog-content">
        <button type="button" class="stock-notify-close" data-stock-notify-close aria-label="Zatvori obavijest o dostupnosti"><x-fa-icon name="xmark" /></button>
        <span class="stock-notify-heading-icon" aria-hidden="true"><x-fa-icon name="bell" /></span>
        <p class="stock-notify-eyebrow">Ne propustite povratak u ponudu</p>
        <h2 id="stock-notify-title">Javimo vam kada stigne</h2>
        <p id="stock-notify-description">Ostavite e-mail i poslat ćemo vam obavijest kada ponovno bude dostupno.</p>
        <p class="stock-notify-product" data-stock-notify-product></p>
        @include('front.partials.stock-notification-form', ['stockNotifyFormId' => 'stock-notify-modal'])
    </div>
</dialog>
