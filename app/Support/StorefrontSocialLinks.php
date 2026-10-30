<?php

namespace App\Support;

use Illuminate\Support\Collection;

class StorefrontSocialLinks
{
    /** @return Collection<int, array{network:string,label:string,url:string}> */
    public static function resolve(array $settings, array $topBarSocials = [], bool $lavaggio = false): Collection
    {
        $settings = collect($settings)->filter(fn ($social) => is_array($social));
        $configured = $settings
            ->filter(fn ($social) => (bool) ($social['enabled'] ?? true) && trim((string) ($social['url'] ?? '')) !== '')
            ->map(fn ($social, $network) => [
                'network' => $social['network'] ?? $network,
                'label' => $social['label'] ?? '',
                'url' => trim((string) $social['url']),
            ]);
        $configuredNetworks = $configured->pluck('network');
        $fallbacks = collect($topBarSocials)
            ->filter(fn ($social) => is_array($social) && ! empty($social['is_active']) && trim((string) ($social['url'] ?? '')) !== '')
            ->filter(fn ($social) => (bool) ($settings->get($social['network'])['enabled'] ?? true))
            ->reject(fn ($social) => $configuredNetworks->contains($social['network']));

        // Both locations use the configured URLs and respect disabled networks.
        $links = $configured->values()->concat($fallbacks)->unique('url')->values();
        if ($lavaggio) {
            $order = array_flip(['tiktok', 'instagram', 'youtube', 'facebook', 'linkedin']);
            $links = $links->sortBy(fn ($social) => $order[$social['network']] ?? 99)->values();
        }

        return $links;
    }
}
