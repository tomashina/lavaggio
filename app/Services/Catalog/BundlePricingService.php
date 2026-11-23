<?php

namespace App\Services\Catalog;

use App\Models\Catalog\Bundle\Bundle;
use App\Models\User;
use App\Models\User\CustomerGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class BundlePricingService
{
    /** Allocate each unit once, in the administrator's bundle order. */
    public function apply(Collection $lines, ?User $user = null): Collection
    {
        if ($lines->isEmpty() || ! Schema::hasTable('catalog_bundles')) {
            return $lines;
        }
        $groupIds = $this->groupIds($user);
        if ($groupIds === []) {
            return $lines;
        }
        $bundles = Bundle::available()->with(['products', 'discounts'])->orderBy('sort_order')->orderBy('id')->get();

        return $this->applyBundles($lines, $bundles, $groupIds);
    }

    public function applyBundle(Collection $lines, Bundle $bundle, array $groupIds): Collection
    {
        if (! $bundle->is_active || $bundle->starts_at?->isFuture() || $bundle->ends_at?->isPast()) {
            return $lines;
        }

        return $this->applyBundles($lines, collect([$bundle]), $groupIds);
    }

    private function applyBundles(Collection $lines, Collection $bundles, array $groupIds): Collection
    {
        $originalLines = clone $lines;
        $remaining = [];
        $lineKeys = [];
        foreach ($lines as $i => $line) {
            // Contract prices and selected variants keep their existing pricing rules.
            if (! ($line['price_is_final'] ?? false) && empty($line['product_option_value_id'])) {
                $remaining[$line['product']->id] = $line['quantity'];
                $lineKeys[$line['product']->id] = $i;
            }
        }
        foreach ($bundles as $bundle) {
            if ($bundle->products->count() < 2 || $bundle->products->contains(fn ($p) => ! $p->is_active)) {
                continue;
            }
            $discount = $bundle->discounts->whereIn('customer_group_id', $groupIds)->sortByDesc('value')->first();
            if (! $discount || (float) $discount->value <= 0) {
                continue;
            }
            $sets = PHP_INT_MAX;
            foreach ($bundle->products as $p) {
                $sets = min($sets, intdiv($remaining[$p->id] ?? 0, max(1, (int) $p->pivot->quantity)));
            }
            if ($sets < 1) {
                continue;
            }
            $netCents = $taxCents = [];
            foreach ($bundle->products->sortBy('id') as $p) {
                $i = $lineKeys[$p->id];
                $used = $p->pivot->quantity * $sets;
                $netCents[$i] = (int) round($originalLines[$i]['unit_price'] * $used * 100);
                $taxCents[$i] = (int) round($originalLines[$i]['unit_tax_amount'] * $used * 100);
            }
            $net = array_sum($netCents);
            $tax = array_sum($taxCents);
            $gross = $net + $tax;
            if ($gross === 0) {
                continue;
            }
            $saving = min($gross, (int) round($discount->type === 'percent'
                ? $gross * $discount->value / 100
                : $discount->value * $sets * 100));
            [$netSaving, $taxSaving] = $this->allocateCents($saving, [$net, $tax]);
            $netDiscounts = $this->allocateCents($netSaving, $netCents);
            $taxDiscounts = $this->allocateCents($taxSaving, $taxCents);
            foreach ($bundle->products as $p) {
                $used = $p->pivot->quantity * $sets;
                $remaining[$p->id] -= $used;
                $i = $lineKeys[$p->id];
                $line = $lines[$i];
                $netDiscount = $netDiscounts[$i] / 100;
                $taxDiscount = $taxDiscounts[$i] / 100;
                $line['line_total'] = round($line['line_total'] - $netDiscount, 2);
                $line['line_tax_total'] = round($line['line_tax_total'] - $taxDiscount, 2);
                $line['line_discount_total'] = round($line['line_discount_total'] + $netDiscount, 2);
                $line['unit_price'] = $line['line_total'] / $line['quantity'];
                $line['unit_tax_amount'] = $line['line_tax_total'] / $line['quantity'];
                $line['unit_discount'] = $line['line_discount_total'] / $line['quantity'];
                $line['display_line_total'] = round($line['line_total'] + (($line['display_includes_tax'] ?? true) ? $line['line_tax_total'] : 0), 2);
                $line['display_unit_price'] = $line['display_line_total'] / $line['quantity'];
                $line['bundle_ids'][] = $bundle->id;
                $line['has_promotional_discount'] = true;
                $line['price_source'] = 'bundle';
                $lines[$i] = $line;
            }
        }

        return $lines;
    }

    /** Round cumulative shares so all line allocations add up to the exact saving. */
    private function allocateCents(int $amount, array $weights): array
    {
        $total = array_sum($weights);
        $cumulative = $allocated = 0;
        $shares = [];
        foreach ($weights as $key => $weight) {
            $cumulative += $weight;
            $target = $total > 0 ? (int) round($amount * ($cumulative / $total)) : 0;
            $shares[$key] = $target - $allocated;
            $allocated = $target;
        }

        return $shares;
    }

    public function groupIds(?User $user): array
    {
        if ($user) {
            $ids = $user->customerGroups()->where('customer_groups.is_active', true)->pluck('customer_groups.id')->all();
            if ($ids) {
                return $ids;
            }
        }
        $default = CustomerGroup::where('is_active', true)->where('is_default', true)->value('id');

        return $default ? [(int) $default] : [];
    }
}
