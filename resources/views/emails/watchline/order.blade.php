@component('emails.watchline.layout', ['title' => __('mail.orders.mail_title', ['order' => $order_number])])
<h1 class="title">
                    {{ $variant === 'admin' ? __('mail.orders.heading_admin') : __('mail.orders.heading_customer') }}
                    #{{ $order_number }}
                </h1>
                <p class="muted">
                    {{ $variant === 'admin' ? __('mail.orders.intro_admin') : __('mail.orders.intro_customer') }}
                </p>

                <table class="meta" width="100%" cellspacing="0" cellpadding="0">
                    <tr><td class="label">{{ __('mail.orders.customer') }}</td><td>{{ $customer_name }}</td></tr>
                    @if (!empty($customer_contact))<tr><td class="label">{{ __('Kontakt') }}</td><td>{{ $customer_contact }}</td></tr>@endif
                    <tr><td class="label">{{ __('mail.orders.email') }}</td><td><a href="mailto:{{ $customer_email }}" style="color:#0f172a !important; text-decoration:none !important;">{{ $customer_email }}</a></td></tr>
                    <tr><td class="label">{{ __('mail.orders.phone') }}</td><td>{{ $customer_phone }}</td></tr>
                    <tr><td class="label">{{ __('mail.orders.placed_at') }}</td><td>{{ $placed_at }}</td></tr>
                    <tr><td class="label">{{ __('mail.orders.payment') }}</td><td>{{ $payment_method }}</td></tr>
                    <tr><td class="label">{{ __('mail.orders.shipping') }}</td><td>{{ $shipping_method }}</td></tr>
                    <tr><td class="label">{{ __('mail.orders.billing_address') }}</td><td>{{ $billing_address }}</td></tr>
                    <tr><td class="label">{{ __('mail.orders.shipping_address') }}</td><td>{{ $shipping_address }}</td></tr>
                </table>

                @if (!empty($box_now) && !empty($box_now['locker_id']))
                    <div class="bank">
                        <h3>{{ __('mail.orders.boxnow_title') }}</h3>
                        <p><strong>{{ __('mail.orders.boxnow_locker') }}:</strong> {{ $box_now['locker_name'] ?: '-' }} ({{ $box_now['locker_id'] }})</p>
                        <p>
                            <strong>{{ __('mail.orders.boxnow_address') }}:</strong>
                            {{ trim(($box_now['address_line_1'] ?? '').', '.($box_now['postal_code'] ?? '').' '.($box_now['city'] ?? ''), ', ') ?: '-' }}
                        </p>
                    </div>
                @endif

                @if (!empty($gls_locker['id']))
                    <div class="panel">
                        <h3>GLS paketomat</h3>
                        <p><strong>{{ $gls_locker['name'] ?: $gls_locker['id'] }}</strong></p>
                        <p>{{ trim(($gls_locker['address_line_1'] ?? '').', '.($gls_locker['postal_code'] ?? '').' '.($gls_locker['city'] ?? ''), ', ') }}</p>
                    </div>
                @endif

                <table class="items" cellspacing="0" cellpadding="0">
                    <thead>
                        <tr>
                            <th width="72">{{ __('mail.orders.image') }}</th>
                            <th>{{ __('mail.orders.product') }}</th>
                            <th class="num">{{ __('mail.orders.qty') }}</th>
                            <th class="num">{{ __('mail.orders.price') }}</th>
                            <th class="num">{{ __('mail.orders.total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($items as $item)
                            <tr>
                                <td>
                                    @if (!empty($item['image_url']))
                                        <div class="img-wrap">
                                            <img src="{{ $item['image_url'] }}" alt="{{ $item['name'] }}" class="img">
                                        </div>
                                    @else
                                        <div class="img-wrap"></div>
                                    @endif
                                </td>
                                <td>
                                    @if (!empty($item['product_url']))
                                        <a href="{{ $item['product_url'] }}" class="name" style="color:#0f172a !important; text-decoration:none !important;">{{ $item['name'] }}</a>
                                    @else
                                        <span class="name">{{ $item['name'] }}</span>
                                    @endif
                                    @if (!empty($item['sku']))
                                        <div class="sku">{{ __('mail.orders.sku') }}: {{ $item['sku'] }}</div>
                                    @endif
                                    @if (!empty($item['option_label']))
                                        <div class="sku">{{ __('mail.orders.option') }}: {{ $item['option_label'] }}</div>
                                    @endif
                                </td>
                                <td class="num"><span class="mobile-label">{{ __('mail.orders.qty') }}: </span>{{ $item['quantity'] }}</td>
                                <td class="num"><span class="mobile-label">{{ __('mail.orders.price') }}: </span>{{ $item['unit_price'] }}</td>
                                <td class="num"><span class="mobile-label">{{ __('mail.orders.total') }}: </span>{{ $item['line_total'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <table class="totals" cellspacing="0" cellpadding="0">
                    <tr><td class="k">{{ __('mail.orders.subtotal') }}</td><td class="num">{{ $totals['subtotal'] }}</td></tr>
                    @if (($totals_raw['discount'] ?? 0) > 0)
                        <tr><td class="k">{{ __('mail.orders.discount') }}</td><td class="num">-{{ $totals['discount'] }}</td></tr>
                    @endif
                    <tr><td class="k">{{ __('mail.orders.shipping') }}</td><td class="num">{{ $totals['shipping'] }}</td></tr>
                    <tr><td class="k">{{ __('mail.orders.payment_fee') }}</td><td class="num">{{ $totals['payment_fee'] }}</td></tr>
                    <tr><td class="k">{{ __('mail.orders.tax') }}</td><td class="num">{{ $totals['tax'] }}</td></tr>
                    <tr><td>{{ __('mail.orders.total') }}</td><td class="num">{{ $totals['grand_total'] }}</td></tr>
                </table>

                @if (!empty($bank_transfer) && !empty($bank_transfer['receiver_iban']))
                    <div class="bank">
                        <h3>{{ __('mail.orders.bank_transfer_title') }}</h3>
                        @if (!empty($bank_transfer['preview_only']))
                            <p><strong>OGLEDNI PRIKAZ · NIJE ZA UPLATU</strong></p>
                            <p>Podaci su testni. Nemojte izvršavati uplatu prema ovoj poruci.</p>
                        @else
                            <p>{{ __('mail.orders.bank_transfer_note') }}</p>
                        @endif
                        <p><strong>{{ __('mail.orders.bank_recipient') }}:</strong> {{ $bank_transfer['receiver_name'] ?? '-' }}</p>
                        <p><strong>{{ __('mail.orders.bank_iban') }}:</strong> {{ $bank_transfer['receiver_iban'] ?? '-' }}</p>
                        <p><strong>{{ __('mail.orders.bank_model') }}:</strong> {{ $bank_transfer['model'] ?? '-' }}</p>
                        <p><strong>{{ __('mail.orders.bank_reference') }}:</strong> {{ $bank_transfer['reference'] ?? '-' }}</p>
                        <p><strong>{{ __('mail.orders.bank_amount') }}:</strong> {{ number_format((float) ($bank_transfer['amount'] ?? 0), 2, ',', '.') }} {{ $currency }}</p>
                        <p><strong>{{ __('mail.orders.bank_description') }}:</strong> {{ $bank_transfer['description'] ?? '-' }}</p>

                        @include('emails.watchline.bank-barcode', ['bankTransfer' => $bank_transfer, 'reference' => $order_number])
                    </div>
                @endif

                @if (!empty($customer_note))
                    <div class="note">
                        <strong>{{ __('mail.orders.customer_note') }}:</strong><br>
                        {{ $customer_note }}
                    </div>
                @endif
                @if (($variant ?? '') === 'customer' && ($legal_warranty['enabled'] ?? false) && ($legal_warranty['email_enabled'] ?? true))
                    <div style="margin-top:20px;padding:16px;border:2px solid #00b5cc;background:#f7f7f7;color:#32373b">
                        <h2 style="margin:0 0 12px;font-size:18px">{{ __('herrera.warranty.title') }}</h2>
                        <p style="margin:0 0 10px"><a href="{{ $legal_warranty['asset_url'] }}">{{ __('herrera.warranty.view_notice') }}</a></p>
                        <p style="margin:0"><a href="{{ $legal_warranty['eu_url'] }}">{{ __('herrera.warranty.eu_information') }}</a></p>
                    </div>
                @endif
@endcomponent
