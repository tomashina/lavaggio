@component('emails.watchline.layout', ['title' => __('return_request.mail.customer_heading')])
<p class="eyebrow">Vaš zahtjev za povrat</p><h1>{{ __('return_request.mail.customer_heading') }}</h1>
<p class="intro">{{ __('return_request.mail.customer_intro') }}</p>
                <p class="reference">
                    <strong>{{ __('return_request.mail.reference') }}:</strong> {{ $withdrawal->reference }}<br>
                    <strong>{{ __('return_request.mail.submitted_at') }}:</strong> {{ $withdrawal->submitted_at?->format('d.m.Y. H:i:s T') }}<br>
                    <strong>{{ __('return_request.mail.confirmation_channel') }}:</strong> {{ __('return_request.mail.confirmation_channel_email') }} — {{ $withdrawal->email }}
                </p>

                <div class="statement">{{ $withdrawal->declaration }}</div>

                <table class="details" role="presentation">
                    <tr><th>{{ __('return_request.mail.full_name') }}</th><td>{{ $withdrawal->full_name }}</td></tr>
                    <tr><th>{{ __('return_request.mail.email') }}</th><td>{{ $withdrawal->email }}</td></tr>
                    <tr><th>{{ __('return_request.mail.phone') }}</th><td>{{ $withdrawal->phone ?: '—' }}</td></tr>
                    <tr><th>{{ __('return_request.mail.address') }}</th><td>{{ $withdrawal->address_line }}, {{ $withdrawal->postal_code }} {{ $withdrawal->city }}, {{ $withdrawal->country_code }}</td></tr>
                    <tr><th>{{ __('return_request.mail.order_number') }}</th><td>{{ $withdrawal->order_number }}</td></tr>
                    <tr><th>{{ __('return_request.mail.contract_date') }}</th><td>{{ $withdrawal->contract_date?->format('d.m.Y.') ?: '—' }}</td></tr>
                    <tr><th>{{ __('return_request.mail.received_date') }}</th><td>{{ $withdrawal->received_date?->format('d.m.Y.') ?: '—' }}</td></tr>
                </table>

                <div class="block">
                    <h2>{{ __('return_request.mail.items') }}</h2>
                    <p>{{ $withdrawal->items }}</p>
                </div>
                <div class="block">
                    <h2>{{ __('return_request.mail.note') }}</h2>
                    <p>{{ $withdrawal->note ?: '—' }}</p>
                </div>
                @if ($returnAddress !== '')
                    <div class="block">
                        <h2>{{ __('return_request.mail.return_address') }}</h2>
                        <p>{{ $returnAddress }}</p>
                    </div>
                @endif
                @if ($instructions !== '')
                    <div class="block">
                        <h2>{{ __('return_request.mail.instructions') }}</h2>
                        <p>{{ $instructions }}</p>
                    </div>
                @endif
@endcomponent
