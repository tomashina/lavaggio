@component('emails.watchline.layout', ['title' => __('return_request.mail.admin_heading')])
<p class="eyebrow">{{ \App\Support\Mail\StoreMailBrand::name() }} · Obavijest za trgovinu</p><h1>{{ __('return_request.mail.admin_heading') }}</h1>
<p class="intro">{{ __('return_request.mail.admin_intro') }}</p>
                <div class="statement">{{ $withdrawal->declaration }}</div>
                <table class="details" role="presentation">
                    <tr><th>{{ __('return_request.mail.reference') }}</th><td>{{ $withdrawal->reference }}</td></tr>
                    <tr><th>{{ __('return_request.mail.submitted_at') }}</th><td>{{ $withdrawal->submitted_at?->format('d.m.Y. H:i:s T') }}</td></tr>
                    <tr><th>{{ __('return_request.mail.full_name') }}</th><td>{{ $withdrawal->full_name }}</td></tr>
                    <tr><th>{{ __('return_request.mail.email') }}</th><td>{{ $withdrawal->email }}</td></tr>
                    <tr><th>{{ __('return_request.mail.phone') }}</th><td>{{ $withdrawal->phone ?: '—' }}</td></tr>
                    <tr><th>{{ __('return_request.mail.address') }}</th><td>{{ $withdrawal->address_line }}, {{ $withdrawal->postal_code }} {{ $withdrawal->city }}, {{ $withdrawal->country_code }}</td></tr>
                    <tr><th>{{ __('return_request.mail.order_number') }}</th><td>{{ $withdrawal->order_number }}</td></tr>
                    <tr><th>{{ __('return_request.mail.contract_date') }}</th><td>{{ $withdrawal->contract_date?->format('d.m.Y.') ?: '—' }}</td></tr>
                    <tr><th>{{ __('return_request.mail.received_date') }}</th><td>{{ $withdrawal->received_date?->format('d.m.Y.') ?: '—' }}</td></tr>
                </table>
                <div class="block"><h2>{{ __('return_request.mail.items') }}</h2><p>{{ $withdrawal->items }}</p></div>
                <div class="block"><h2>{{ __('return_request.mail.note') }}</h2><p>{{ $withdrawal->note ?: '—' }}</p></div>
                <a href="{{ $adminUrl }}" class="cta">{{ __('return_request.mail.admin_cta') }}</a>
@endcomponent
