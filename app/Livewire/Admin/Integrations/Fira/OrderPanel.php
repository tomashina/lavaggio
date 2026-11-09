<?php

namespace App\Livewire\Admin\Integrations\Fira;

use App\Models\Sales\Order\Order;
use App\Services\Admin\OrderManagerAccess;
use App\Services\Integrations\Fira\FiraInvoiceService;
use App\Services\Integrations\Fira\FiraOrderPayload;
use App\Services\Integrations\Fira\FiraSettingsService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

class OrderPanel extends Component
{
    #[Locked]
    public int $orderId;

    public string $paymentType = '';

    public bool $confirmed = false;

    #[Locked]
    public ?array $preview = null;

    public function mount(int $orderId): void
    {
        $this->orderId = $orderId;
        $this->authorizeOrder();
    }

    public function previewInvoice(FiraOrderPayload $payloads): void
    {
        $this->authorizeOrder();
        $this->preview = null;
        $this->confirmed = false;
        $this->resetErrorBag('fira');
        $this->preview = $payloads->build(Order::query()->findOrFail($this->orderId), $this->paymentType);
    }

    public function issueInvoice(FiraInvoiceService $invoices): void
    {
        $this->authorizeOrder();
        $this->validate(['confirmed' => ['accepted']]);
        abort_unless($this->preview !== null, 422);
        $hash = hash('sha256', json_encode($this->preview, JSON_THROW_ON_ERROR));
        $invoices->issue($this->orderId, $this->paymentType, (int) auth()->id(), $hash);
        $this->preview = null;
        $this->confirmed = false;
        $this->dispatch('notify', type: 'success', message: 'FIRA je potvrdila izradu dokumenta. Otvorite ga u FIRA-i za pregled i daljnju obradu.');
    }

    public function render(FiraSettingsService $settings)
    {
        $this->authorizeOrder();

        return view('livewire.admin.integrations.fira.order-panel', [
            'blockers' => $settings->blockers(),
            'documentType' => $settings->options()['document_type'],
            'document' => DB::table('lavaggio_fira_documents')->where('order_id', $this->orderId)->first(),
        ]);
    }

    public function updatedPaymentType(): void
    {
        $this->preview = null;
        $this->confirmed = false;
    }

    private function authorizeOrder(): void
    {
        $user = auth()->user();
        abort_unless(config('storefront.home') === 'lavaggio' && $user
            && ($user->isA('superadmin') || $user->can('admin.access') && $user->can('sales.orders.update')), 403);
        app(OrderManagerAccess::class)->assertOrder(Order::query()->findOrFail($this->orderId), $user);
    }
}
