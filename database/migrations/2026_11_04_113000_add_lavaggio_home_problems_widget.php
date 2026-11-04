<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (config('storefront.home') !== 'lavaggio') {
            return;
        }

        $database = DB::connection($this->getConnection());
        $database->transaction(function () use ($database): void {
            if ($this->hasProblemBlock($database)) {
                return;
            }

            $campaign = $this->homeSlots($database)
                ->join('content_blocks as blocks', 'blocks.id', '=', 'content_block_slots.content_block_id')
                ->where('blocks.type', 'lavaggio_campaigns')
                ->select('content_block_slots.id', 'content_block_slots.sort_order')
                ->orderBy('content_block_slots.sort_order')->orderBy('content_block_slots.id')
                ->lockForUpdate()->first();

            // Fresh installations have no authored homepage yet; their setup owns its defaults.
            if (! $campaign || $this->hasProblemBlock($database)) {
                return;
            }

            $next = $this->homeSlots($database)
                ->where(function (Builder $query) use ($campaign): void {
                    $query->where('sort_order', '>', $campaign->sort_order)
                        ->orWhere(function (Builder $tie) use ($campaign): void {
                            $tie->where('sort_order', $campaign->sort_order)->where('id', '>', $campaign->id);
                        });
                })
                ->orderBy('sort_order')->orderBy('id')->first();

            if ($next && (int) $next->sort_order === (int) $campaign->sort_order) {
                throw new RuntimeException('Lavaggio problem widget was not added: the promo slot and its next homepage slot share sort_order '.$campaign->sort_order.'. Give these existing slots distinct order values and rerun the migration; no existing content was changed.');
            }

            $campaignOrder = (int) $campaign->sort_order;
            $sortOrder = $next
                ? $campaignOrder + intdiv((int) $next->sort_order - $campaignOrder, 2)
                : min(4294967295, $campaignOrder + 1);
            $now = now();
            $blockId = $database->table('content_blocks')->insertGetId([
                'code' => 'lavaggio-home-problems',
                'name' => 'Početna — odabir prema problemu',
                'type' => 'lavaggio_problems',
                'is_active' => true,
                'payload' => json_encode(['lavaggio_widget_version' => 1], JSON_THROW_ON_ERROR),
                'created_by' => null, 'updated_by' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $database->table('content_block_translations')->insert([
                'content_block_id' => $blockId,
                'locale' => 'hr',
                'title' => 'Odaberi deterdžent za problem',
                'subtitle' => 'Krenite od onoga što želite riješiti i pronađite odgovarajuću njegu.',
                'body_html' => null, 'cta_label' => null, 'cta_url' => null,
                'payload' => json_encode(['watchline' => $this->initialSettings()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $slotId = $database->table('content_block_slots')->insertGetId([
                'content_block_id' => $blockId,
                'placement' => 'home.content',
                'frontend_variant' => 'all',
                'target_type' => null, 'target_ref' => null,
                'sort_order' => $sortOrder,
                'is_active' => true,
                'starts_at' => null, 'ends_at' => null,
                'created_by' => null, 'updated_by' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);

            // Adjacent integer positions reuse the promo order and rely on the normal id tie-breaker.
            if ($sortOrder === $campaignOrder && $slotId <= $campaign->id) {
                throw new RuntimeException('Lavaggio problem widget was not added: the new slot id cannot preserve the existing homepage order. No existing content was changed.');
            }
        });
    }

    public function down(): void
    {
        // This is authored content after deployment; a rollback must not delete or reset it.
    }

    private function homeSlots(Connection $database): Builder
    {
        return $database->table('content_block_slots')
            ->where('placement', 'home.content')
            ->whereNull('target_type')
            ->where(fn (Builder $query) => $query->whereNull('frontend_variant')->orWhere('frontend_variant', 'all'));
    }

    private function hasProblemBlock(Connection $database): bool
    {
        return $database->table('content_blocks')->where('code', 'lavaggio-home-problems')->exists()
            || $database->table('content_blocks as blocks')
                ->join('content_block_slots as slots', 'slots.content_block_id', '=', 'blocks.id')
                ->where('blocks.type', 'lavaggio_problems')->where('slots.placement', 'home.content')->exists();
    }

    /** Frozen deployment defaults; later configuration changes must not rewrite migrated content. */
    private function initialSettings(): array
    {
        $settings = ['eyebrow' => 'PRONAĐITE RJEŠENJE'];
        foreach ([
            ['Neugodni mirisi', 'wind', 'odor'],
            ['Sivo i požutjelo rublje', 'shirt', 'grey'],
            ['Tvrdokorne mrlje', 'spray-can-sparkles', 'stains'],
            ['Mrlje od krema i masnoća', 'droplet', 'grease'],
            ['Osjetljive tkanine', 'feather', 'delicate'],
            ['Održavanje perilice rublja', 'washing-machine', 'machine'],
        ] as $offset => [$title, $icon, $target]) {
            $prefix = 'card_'.($offset + 1).'_';
            $settings += [
                $prefix.'title' => $title, $prefix.'icon' => $icon, $prefix.'target' => $target,
                $prefix.'url' => '', $prefix.'order' => ($offset + 1) * 10, $prefix.'enabled' => true,
            ];
        }

        return $settings;
    }
};
