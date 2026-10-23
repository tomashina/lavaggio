<?php

namespace App\Livewire\Admin\Sales\GiftVoucher;

use App\Models\Sales\GiftVoucher;
use App\Services\GiftVoucherDeliveryService;
use App\Services\GiftVoucherService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class Manager extends Component
{
    use WithPagination;

    private const PAGE_NAME = 'giftVouchersPage';

    public string $search = '';

    public string $status = '';

    public string $emailStatus = '';

    public bool $creating = false;

    #[Locked]
    public ?int $detailId = null;

    public array $form = [
        'amount' => 50, 'recipient_name' => '', 'recipient_email' => '',
        'buyer_name' => '', 'buyer_email' => '', 'sender_name' => '',
        'message' => '', 'expires_at' => '',
    ];

    public function mount(): void
    {
        $this->permit('view');
    }

    public function updatedSearch(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function updatedStatus(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function updatedEmailStatus(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function clearFilters(): void
    {
        $this->permit('view');
        $this->reset('search', 'status', 'emailStatus');
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function create(): void
    {
        $this->permit('update');
        $this->reset('form');
        $this->resetValidation();
        $this->creating = true;
    }

    public function closeCreate(): void
    {
        $this->permit('view');
        $this->creating = false;
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->permit('update');
        $data = $this->validate([
            'form.amount' => ['required', 'numeric', 'min:10', 'max:300', 'multiple_of:10'],
            'form.recipient_name' => ['required', 'string', 'max:191'],
            'form.recipient_email' => ['required', 'email', 'max:191'],
            'form.buyer_name' => ['nullable', 'string', 'max:191'],
            'form.buyer_email' => ['nullable', 'email', 'max:191'],
            'form.sender_name' => ['required', 'string', 'max:191'],
            'form.message' => ['nullable', 'string', 'max:1000'],
            'form.expires_at' => ['nullable', 'date', 'after:today'],
        ], [
            'form.amount.required' => 'Odaberite vrijednost poklon bona.',
            'form.amount.numeric' => 'Vrijednost bona mora biti broj.',
            'form.amount.min' => 'Najmanja vrijednost bona je 10 €.',
            'form.amount.max' => 'Najveća vrijednost bona je 300 €.',
            'form.amount.multiple_of' => 'Vrijednost bona mora biti u koracima od 10 €.',
            'form.recipient_name.required' => 'Upišite ime primatelja.',
            'form.recipient_email.required' => 'Upišite e-mail primatelja.',
            'form.recipient_email.email' => 'Upišite ispravan e-mail primatelja.',
            'form.buyer_email.email' => 'Upišite ispravan e-mail kupca.',
            'form.sender_name.required' => 'Upišite ime pošiljatelja.',
            'form.message.max' => 'Poruka može imati najviše 1000 znakova.',
            'form.expires_at.date' => 'Upišite ispravan datum isteka.',
            'form.expires_at.after' => 'Datum isteka mora biti nakon današnjeg dana.',
        ])['form'];
        $data['expires_at'] = $data['expires_at'] ?: null;
        $data['locale'] = 'hr';
        $voucher = app(GiftVoucherService::class)->createManual($data);
        $this->creating = false;
        $this->detailId = (int) $voucher->id;
        $this->reset('form');

        $delivery = app(GiftVoucherDeliveryService::class);
        $result = $delivery->send($voucher);
        $this->dispatch('notify', type: $result['sent'] ? 'success' : 'warning', message: $result['sent']
            ? 'Poklon bon je izdan i poslan primatelju.'
            : 'Poklon bon je izdan. '.$result['message']);
    }

    public function detail(int $id): void
    {
        $this->permit('view');
        GiftVoucher::query()->findOrFail($id);
        $this->detailId = $id;
        $this->resetValidation();
    }

    public function closeDetail(): void
    {
        $this->permit('view');
        $this->detailId = null;
        $this->resetValidation();
    }

    public function send(int $id): void
    {
        $this->permit('update');
        $voucher = GiftVoucher::query()->findOrFail($id);
        $result = app(GiftVoucherDeliveryService::class)->send($voucher, $voucher->email_sent_at !== null);
        $this->dispatch('notify', type: $result['sent'] ? 'success' : 'warning', message: $result['message']);
    }

    public function toggle(int $id): void
    {
        $this->permit('update');
        $voucher = GiftVoucher::query()->findOrFail($id);
        if (! in_array($voucher->status, [GiftVoucher::STATUS_ACTIVE, GiftVoucher::STATUS_DISABLED], true)) {
            $this->addError('voucher', 'Status ovog bona nije moguće ručno promijeniti.');

            return;
        }
        $enabled = $voucher->status === GiftVoucher::STATUS_DISABLED;
        app(GiftVoucherService::class)->setEnabled($voucher, $enabled);
        $this->dispatch('notify', type: 'success', message: $enabled ? 'Poklon bon je ponovno aktiviran.' : 'Poklon bon je onemogućen.');
    }

    public function confirmPayment(int $id): void
    {
        $this->permit('update');
        $voucher = GiftVoucher::query()->with('purchaseOrder')->findOrFail($id);
        if ($voucher->status !== GiftVoucher::STATUS_PENDING || ! $voucher->purchaseOrder || $voucher->purchaseOrder->paid_at !== null) {
            $this->addError('payment', 'Uplatu nije moguće potvrditi. Bon mora čekati plaćanje povezane, još neplaćene narudžbe poklon bona.');

            return;
        }
        app(GiftVoucherService::class)->confirmPurchasePayment($voucher->purchaseOrder);
        $this->dispatch('notify', type: 'success', message: 'Uplata je potvrđena. Poklon bon je spreman za korištenje.');
    }

    public function render()
    {
        $this->permit('view');
        $counts = GiftVoucher::query()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');

        return view('livewire.admin.sales.gift-voucher.manager', [
            'rows' => $this->filteredQuery()->with('purchaseOrder:id,order_number,paid_at,status_id')->latest('id')->paginate(20, ['*'], self::PAGE_NAME),
            'detailVoucher' => $this->detailId ? GiftVoucher::query()->with(['purchaseOrder', 'redemptions.order:id,order_number,grand_total,currency_code'])->find($this->detailId) : null,
            'stats' => [
                'total' => (int) $counts->sum(),
                'active' => (int) $counts->get(GiftVoucher::STATUS_ACTIVE, 0),
                'pending' => (int) $counts->get(GiftVoucher::STATUS_PENDING, 0),
                'issued' => (float) GiftVoucher::query()->whereNotNull('issued_at')->sum('initial_amount'),
                'balance' => (float) GiftVoucher::query()->whereIn('status', [GiftVoucher::STATUS_ACTIVE, GiftVoucher::STATUS_DISABLED])->sum('balance'),
            ],
            'canUpdate' => $this->canUpdate(),
            'emailsEnabled' => app(GiftVoucherDeliveryService::class)->canSend(),
            'amounts' => app(GiftVoucherService::class)->availableAmounts(),
        ]);
    }

    private function filteredQuery(): Builder
    {
        $search = mb_substr(trim($this->search), 0, 200);
        $code = app(GiftVoucherService::class)->normalizeCode($search);
        $id = ltrim($search, '#');

        return GiftVoucher::query()->when($search !== '', fn (Builder $query) => $query->where(function (Builder $match) use ($search, $code, $id): void {
            $match->where('recipient_name', 'like', '%'.$search.'%')->orWhere('recipient_email', 'like', '%'.$search.'%')
                ->orWhere('buyer_name', 'like', '%'.$search.'%')->orWhere('buyer_email', 'like', '%'.$search.'%')
                ->orWhere('code_suffix', 'like', '%'.$search.'%')->orWhere('code_hash', hash('sha256', $code))
                ->orWhereHas('purchaseOrder', fn (Builder $order) => $order->where('order_number', 'like', '%'.$search.'%'));
            if (ctype_digit($id)) {
                $match->orWhere('id', (int) $id)->orWhere('purchase_order_id', (int) $id);
            }
        }))
            ->when(in_array($this->status, ['pending', 'active', 'exhausted', 'disabled', 'cancelled'], true), fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->status === 'expired', fn (Builder $query) => $query->whereNotNull('expires_at')->where('expires_at', '<', now()))
            ->when($this->emailStatus === 'sent', fn (Builder $query) => $query->whereNotNull('email_sent_at'))
            ->when($this->emailStatus === 'waiting', fn (Builder $query) => $query->whereNull('email_sent_at')->whereNull('email_error'))
            ->when($this->emailStatus === 'failed', fn (Builder $query) => $query->whereNotNull('email_error'));
    }

    private function canUpdate(): bool
    {
        $user = auth()->user();

        return $user && ($user->isA('superadmin') || $user->can('sales.orders.update'));
    }

    private function permit(string $ability): void
    {
        $user = auth()->user();
        abort_unless($user && ($user->isA('superadmin') || $user->can('sales.orders.'.$ability)), 403);
    }
}
