<?php

namespace App\Support;

use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Category\Category;

class LavaggioHomeProblems
{
    public static function cards(array $settings): array
    {
        $attributes = Attribute::query()->where('is_active', true)->whereIn('code', [
            'lavaggio-problem-neugodni-mirisi', 'lavaggio-problem-sivilo-bijelog-rublja',
            'lavaggio-problem-masnoca', 'lavaggio-fabric-osjetljive-tkanine',
        ])->pluck('id', 'code');
        $categories = Category::query()->where('scope', Category::SCOPE_CATALOG)->currentlyVisible()
            ->whereIn('code', ['lavaggio-uklanjanje-mrlja', 'lavaggio-ciscenje-i-odrzavanje-perilice'])
            ->with('translations')->get()->keyBy('code');
        $attributeTargets = [
            'odor' => ['problem', 'neugodni-mirisi'], 'grey' => ['problem', 'sivilo-bijelog-rublja'],
            'grease' => ['problem', 'masnoca'], 'delicate' => ['fabric', 'osjetljive-tkanine'],
        ];
        $categoryTargets = ['stains' => 'lavaggio-uklanjanje-mrlja', 'machine' => 'lavaggio-ciscenje-i-odrzavanje-perilice'];
        $cards = [];
        foreach (range(1, 6) as $index) {
            $prefix = 'card_'.$index.'_';
            if (! ($settings[$prefix.'enabled'] ?? true) || trim($settings[$prefix.'title'] ?? '') === '') {
                continue;
            }
            $url = WatchlineWidgetSettings::safeUrl($settings[$prefix.'url'] ?? '');
            $target = $settings[$prefix.'target'] ?? '';
            if ($url === '' && isset($attributeTargets[$target])) {
                [$group, $slug] = $attributeTargets[$target];
                $id = $attributes->get('lavaggio-'.$group.'-'.$slug);
                if ($id) {
                    $url = route('shop.index', ['attr_lavaggio_'.$group => $id]);
                }
            }
            if ($url === '' && isset($categoryTargets[$target])) {
                $category = $categories->get($categoryTargets[$target]);
                $translation = $category?->translations->firstWhere('locale', app()->getLocale())
                    ?? $category?->translations->firstWhere('locale', config('app.fallback_locale'));
                if (filled($translation?->slug)) {
                    $url = route('categories.show', $translation->slug);
                }
            }
            $cards[] = ['title' => $settings[$prefix.'title'], 'icon' => $settings[$prefix.'icon'],
                'url' => $url ?: route('shop.index'), 'order' => (int) ($settings[$prefix.'order'] ?? $index * 10)];
        }
        usort($cards, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return $cards;
    }
}
