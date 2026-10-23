<?php

namespace App\Livewire\Admin\Catalog\StockNotification;

use App\Models\Catalog\StockNotification;
use App\Services\Catalog\StockNotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

class Manager extends Component
{
    use WithPagination;

    private const PAGE_NAME = 'stockNotificationsPage';

    public string $search = '';

    public string $status = '';

    /** @var array<int, int|string> */
    public array $selected = [];

    public function mount(): void
    {
        $this->permit('view');
        $status = (string) request()->query('status', '');
        $this->status = in_array($status, ['', 'ready', 'waiting', 'processing', 'sent', 'failed', 'cancelled'], true) ? $status : '';
    }

    public function updatedSearch(): void
    {
        $this->resetFiltersPage();
    }

    public function updatedStatus(): void
    {
        $this->resetFiltersPage();
    }

    public function clearFilters(): void
    {
        $this->permit('view');
        $this->reset('search', 'status', 'selected');
        $this->resetValidation();
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function selectReady(): void
    {
        $this->permit('update');
        $readyIds = $this->readyNotificationIds();
        $this->selected = $this->filteredQuery($readyIds)
            ->orderByDesc('id')
            ->forPage($this->getPage(self::PAGE_NAME), 20)
            ->get(['id', 'status'])
            ->filter(fn (StockNotification $row) => in_array($row->status, ['waiting', 'failed'], true)
                && in_array((int) $row->id, $readyIds, true))
            ->pluck('id')->values()->all();
    }

    public function send(int $id): void
    {
        $this->permit('update');
        StockNotification::query()->findOrFail($id);
        $result = app(StockNotificationService::class)->sendNow($id);
        $this->selected = array_values(array_filter($this->selected, fn ($selectedId) => (int) $selectedId !== $id));
        $this->dispatch('notify', type: $result['sent'] ? 'success' : 'warning', message: $result['message']);
    }

    public function sendSelected(): void
    {
        $this->permit('update');
        $validated = $this->validate([
            'selected' => ['required', 'array', 'min:1', 'max:100'],
            'selected.*' => ['required', 'integer', 'distinct', 'exists:stock_notifications,id'],
        ], [
            'selected.required' => 'Odaberite barem jednu obavijest.',
            'selected.min' => 'Odaberite barem jednu obavijest.',
            'selected.max' => 'Odjednom možete poslati najviše 100 obavijesti.',
            'selected.*.exists' => 'Jedna od odabranih obavijesti više ne postoji.',
            'selected.*.integer' => 'Odabir obavijesti nije valjan.',
            'selected.*.distinct' => 'Ista obavijest može biti odabrana samo jednom.',
        ]);

        if (! app(StockNotificationService::class)->canSend()) {
            $this->dispatch('notify', type: 'warning', message: 'Slanje e-pošte trenutačno nije uključeno ili postavljeno. Prijave ostaju sačuvane.');

            return;
        }

        $sent = $skipped = $failed = 0;
        foreach ($validated['selected'] as $id) {
            $result = app(StockNotificationService::class)->sendNow((int) $id);
            if ($result['sent']) {
                $sent++;
            } elseif (in_array($result['reason'] ?? '', ['failed', 'unconfirmed'], true)) {
                $failed++;
            } else {
                $skipped++;
            }
        }

        $this->selected = [];
        $message = "Poslano: {$sent}. Preskočeno: {$skipped}. Neuspjelo: {$failed}.";
        $this->dispatch('notify', type: $failed > 0 ? 'warning' : 'success', message: $message);
    }

    public function cancel(int $id): void
    {
        $this->permit('update');
        StockNotification::query()->findOrFail($id);
        $cancelled = StockNotification::query()->whereKey($id)->whereIn('status', ['waiting', 'failed'])
            ->update(['status' => 'cancelled', 'updated_at' => now()]);
        $this->selected = array_values(array_filter($this->selected, fn ($selectedId) => (int) $selectedId !== $id));
        $this->dispatch('notify', type: $cancelled ? 'success' : 'warning', message: $cancelled
            ? 'Prijava je otkazana. Kupcu se neće poslati obavijest.'
            : 'Ovu prijavu trenutačno nije moguće otkazati.');
    }

    public function markInterrupted(int $id): void
    {
        $this->permit('update');
        StockNotification::query()->findOrFail($id);
        $maxAttempts = max(1, (int) config('stock_notifications.max_attempts', 3));
        $updated = StockNotification::query()->whereKey($id)->where('status', 'processing')
            ->where('last_attempt_at', '<=', now()->subMinutes(30))
            ->update([
                'status' => 'failed',
                'claim_token' => null,
                'attempts' => DB::raw("CASE WHEN attempts < {$maxAttempts} THEN {$maxAttempts} ELSE attempts END"),
                'last_error' => 'Prekinuto slanje – prije ponovnog pokušaja provjerite je li poruka već dostavljena.',
                'updated_at' => now(),
            ]);

        $this->dispatch('notify', type: $updated ? 'success' : 'warning', message: $updated
            ? 'Slanje je označeno kao prekinuto. Prije ručnog ponovnog pokušaja provjerite je li poruka već dostavljena. Automatsko slanje je za ovu prijavu zaustavljeno.'
            : 'Možete označiti samo slanje koje je započelo prije najmanje 30 minuta.');
    }

    public function render()
    {
        $this->permit('view');
        $readyIds = $this->readyNotificationIds();
        $counts = StockNotification::query()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $readyCount = count($readyIds);
        $waitingReadyCount = StockNotification::query()->where('status', 'waiting')->whereIn('id', $readyIds)->count();

        $top = StockNotification::query()->selectRaw('target_key, MIN(id) AS representative_id, COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN status IN ('waiting', 'failed') THEN 1 ELSE 0 END) AS pending_total")
            ->selectRaw("SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent_total")
            ->groupBy('target_key')->orderByDesc('pending_total')->orderByDesc('total')->limit(5)->get();
        $representatives = StockNotification::query()->with($this->relations())->whereIn('id', $top->pluck('representative_id'))->get()->keyBy('id');

        return view('livewire.admin.catalog.stock-notification.manager', [
            'rows' => $this->filteredQuery($readyIds)->with($this->relations())->orderByDesc('id')->paginate(20, ['*'], self::PAGE_NAME),
            'readyIds' => $readyIds,
            'stats' => [
                'total' => (int) $counts->sum(),
                'waiting' => max(0, (int) $counts->get('waiting', 0) - $waitingReadyCount),
                'ready' => $readyCount,
                'sent' => (int) $counts->get('sent', 0),
                'failed' => (int) $counts->get('failed', 0),
                'cancelled' => (int) $counts->get('cancelled', 0),
            ],
            'topProducts' => $top->map(fn ($row) => [
                'notification' => $representatives->get($row->representative_id),
                'total' => (int) $row->total,
                'pending' => (int) $row->pending_total,
                'sent' => (int) $row->sent_total,
            ]),
            'canUpdate' => $this->canUpdate(),
            'emailsEnabled' => app(StockNotificationService::class)->canSend(),
        ]);
    }

    /** Evaluate once per target, recipient and language; group access can differ by buyer. */
    private function readyNotificationIds(): array
    {
        $pending = StockNotification::query()->whereIn('status', ['waiting', 'failed'])
            ->get(['id', 'target_key', 'user_id', 'locale']);
        $groups = $pending->groupBy(fn (StockNotification $row) => $this->readinessIdentity($row));
        $ids = $groups->map(fn ($group) => $group->first()->id)->values();
        $availableIdentities = StockNotification::query()->with($this->relations())->whereIn('id', $ids)->get()
            ->filter(fn (StockNotification $notification) => $notification->isAvailable())
            ->mapWithKeys(fn (StockNotification $row) => [$this->readinessIdentity($row) => true])->all();

        return $pending->filter(fn (StockNotification $row) => isset($availableIdentities[$this->readinessIdentity($row)]))
            ->map(fn (StockNotification $row) => (int) $row->id)->values()->all();
    }

    private function readinessIdentity(StockNotification $row): string
    {
        return json_encode([$row->target_key, $row->user_id, $row->locale], JSON_THROW_ON_ERROR);
    }

    private function filteredQuery(array $readyIds): Builder
    {
        $search = mb_substr(trim($this->search), 0, 200);

        return StockNotification::query()
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery->where('email', 'like', '%'.$search.'%')
                    ->orWhereHas('product', fn (Builder $product) => $product->where('sku', 'like', '%'.$search.'%')
                        ->orWhereHas('translations', fn (Builder $translation) => $translation->where('name', 'like', '%'.$search.'%')))
                    ->orWhereHas('bundle', fn (Builder $bundle) => $bundle->where('title', 'like', '%'.$search.'%'));
            }))
            ->when($this->status === 'ready', fn (Builder $query) => $query->whereIn('status', ['waiting', 'failed'])->whereIn('id', $readyIds))
            ->when($this->status === 'waiting', fn (Builder $query) => $query->where('status', 'waiting')->whereNotIn('id', $readyIds))
            ->when(in_array($this->status, ['processing', 'sent', 'failed', 'cancelled'], true), fn (Builder $query) => $query->where('status', $this->status));
    }

    private function relations(): array
    {
        return StockNotificationService::relations();
    }

    private function resetFiltersPage(): void
    {
        $this->selected = [];
        $this->resetValidation();
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    private function canUpdate(): bool
    {
        $user = auth()->user();

        return $user && ($user->isA('superadmin') || $user->can('catalog.products.update'));
    }

    private function permit(string $ability): void
    {
        $user = auth()->user();
        abort_unless($user && ($user->isA('superadmin') || $user->can('catalog.products.'.$ability)), 403);
    }
}
