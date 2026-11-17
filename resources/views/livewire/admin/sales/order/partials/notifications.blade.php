<section class="admin-panel admin-panel-soft p-5" data-order-notifications>
    <h2 class="admin-section-title">Povijest e-mail obavijesti</h2>
    <p class="mt-1 text-xs text-slate-500">Obavijesti o statusu i praćenju pošiljke. „Poslano” znači da je poslužitelj prihvatio poruku za slanje.</p>
    <div class="mt-4 space-y-3">
        @forelse ($order->notifications as $notification)
            <article wire:key="notification-{{ $notification->id }}" class="rounded-lg border border-slate-200 bg-white p-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <strong class="text-sm text-slate-800">{{ $notification->subject }}</strong>
                    <span class="rounded-full px-2 py-1 text-xs font-semibold {{ $notification->status === 'sent' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-900' }}">{{ $notification->statusLabel() }}</span>
                </div>
                <p class="mt-1 text-xs text-slate-600">Primatelj: {{ $notification->recipient ?: 'Nema e-mail adrese' }}</p>
                <p class="text-xs text-slate-500">
                    Kreirano: {{ $notification->created_at->timezone('Europe/Zagreb')->format('d.m.Y. H:i:s') }}
                    @if ($notification->sent_at) · Poslano: {{ $notification->sent_at->timezone('Europe/Zagreb')->format('d.m.Y. H:i:s') }} @endif
                </p>
                @if ($tracking = data_get($notification->content, 'tracking'))
                    <p class="mt-1 text-sm text-slate-700">{{ $tracking['carrier'] }} · Broj za praćenje:
                        <a href="{{ $tracking['url'] }}" target="_blank" rel="noopener noreferrer" class="underline">{{ $tracking['number'] }}</a>
                    </p>
                @endif
                @if ($notification->last_error)
                    <p class="mt-2 text-xs text-amber-800">{{ $notification->last_error }}</p>
                @endif
                <details class="mt-2 text-xs text-slate-600">
                    <summary class="cursor-pointer font-semibold">Sadržaj obavijesti i pokušaji slanja</summary>
                    <p class="mt-2">{{ data_get($notification->content, 'message') }}</p>
                    <p>Status: {{ data_get($notification->content, 'status_name') }}</p>
                    <ul class="mt-2 space-y-1">
                        @foreach ($notification->attempts ?? [] as $attempt)
                            <li>
                                {{ \Carbon\Carbon::parse($attempt['at'])->timezone('Europe/Zagreb')->format('d.m.Y. H:i:s') }} ·
                                {{ match ($attempt['status']) { 'sent' => 'Poslano', 'skipped' => 'Nije poslano', default => 'Slanje nije uspjelo' } }}
                                · {{ $attempt['by'] ? 'Ponovljeno slanje (administrator #'.$attempt['by'].')' : 'Automatski' }}
                                @if ($attempt['error']) · {{ $attempt['error'] }} @endif
                            </li>
                        @endforeach
                    </ul>
                </details>
                @if ($notification->canRetry() && auth()->user()?->can('sales.orders.update'))
                    <button type="button" wire:click="retryNotification({{ $notification->id }})" wire:loading.attr="disabled" wire:target="retryNotification" class="mt-3 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 disabled:opacity-50">
                        {{ $notification->status === 'pending' ? 'Pošalji obavijest' : 'Pokušaj ponovno poslati' }}
                    </button>
                @endif
            </article>
        @empty
            <p class="text-sm text-slate-500">Još nema zabilježenih obavijesti o statusu ili praćenju pošiljke.</p>
        @endforelse
    </div>
</section>
