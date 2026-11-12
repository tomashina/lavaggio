<?php

namespace App\Console\Commands;

use App\Models\Sales\Order\BoxNowShipment;
use App\Services\Integrations\BoxNow\BoxNowShipmentService;
use Illuminate\Console\Command;

class SyncBoxNowShipments extends Command
{
    protected $signature = 'boxnow:sync {--order= : Order ID to refresh} {--limit=100 : Maximum shipments per run}';

    protected $description = 'Refresh BOX NOW tracking and customer-visible order history';

    public function handle(BoxNowShipmentService $service): int
    {
        $query = BoxNowShipment::query()->whereNotNull('attempted_at');
        if ($this->option('order')) {
            $query->where('order_id', (int) $this->option('order'));
        } else {
            $query->where(function ($q): void {
                $q->whereNull('state')->orWhereNotIn('state', ['delivered', 'returned', 'canceled'])
                    ->orWhere('state_at', '>=', now()->subDays(14));
            });
        }
        $failed = 0;
        $shipments = $query->orderBy('last_synced_at')->orderBy('id')->limit(max(1, min(500, (int) $this->option('limit'))))->get();
        foreach ($shipments as $shipment) {
            try {
                $service->sync($shipment);
            } catch (\Throwable $e) {
                $failed++;
                $this->warn('Order #'.$shipment->order_id.': '.$e->getMessage());
            }
        }
        $this->info('BOX NOW: '.$shipments->count().' checked, '.$failed.' failed.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
