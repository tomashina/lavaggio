<?php

namespace App\Services\Content;

use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Collection;

class LavaggioContactCards
{
    public const SETTING = 'lavaggio_contact_cards';

    public const ICONS = ['phone', 'envelope', 'arrows-rotate', 'location-dot', 'comments', 'bag-shopping', 'clock', 'truck'];

    public function __construct(private readonly SystemSettingsService $settings) {}

    public static function defaults(): array
    {
        return [
            ['title' => 'Web podrška', 'icon' => 'phone', 'type' => 'phone', 'text' => '', 'link_label' => '', 'url' => '', 'show_hours' => true, 'sort_order' => 0, 'is_active' => true],
            ['title' => 'Kontakt mailom', 'icon' => 'envelope', 'type' => 'email', 'text' => 'Pišite nam, rado ćemo pomoći.', 'link_label' => '', 'url' => '', 'show_hours' => false, 'sort_order' => 10, 'is_active' => true],
            ['title' => 'Reklamacije', 'icon' => 'arrows-rotate', 'type' => 'returns', 'text' => 'Kako mogu podnijeti reklamaciju?', 'link_label' => 'Povrati i reklamacije', 'url' => '', 'show_hours' => false, 'sort_order' => 20, 'is_active' => true],
            ['title' => 'Prodajno mjesto Barban', 'icon' => 'location-dot', 'type' => 'store', 'text' => 'Barban 70, 52207 Barban', 'link_label' => '', 'url' => '', 'show_hours' => true, 'sort_order' => 30, 'is_active' => true],
        ];
    }

    public function editable(): array
    {
        $cards = $this->settings->get(self::SETTING, null);

        return is_array($cards) ? array_values($cards) : self::defaults();
    }

    public function forStorefront(array $footer): Collection
    {
        return collect($this->editable())
            ->filter(fn ($card) => is_array($card) && ($card['is_active'] ?? true))
            ->sortBy(fn ($card) => (int) ($card['sort_order'] ?? 0))
            ->map(function (array $card) use ($footer): array {
                $type = (string) ($card['type'] ?? 'custom');
                $phone = (string) ($footer['phone'] ?? '');
                $email = (string) ($footer['email_support'] ?? '');
                $url = trim((string) ($card['url'] ?? ''));
                if ($url !== '' && ! preg_match('~^(?:/(?!/)|https?://)~i', $url)) {
                    $url = '';
                }
                $href = match ($type) {
                    'phone' => $phone !== '' ? 'tel:'.preg_replace('/[^+\d]/', '', $phone) : '',
                    'email' => $email !== '' ? 'mailto:'.$email : '',
                    'returns' => route('pages.show', 'povrati-i-reklamacije'),
                    default => $url,
                };

                return [
                    'title' => trim((string) ($card['title'] ?? '')),
                    'icon' => in_array($card['icon'] ?? '', self::ICONS, true) ? $card['icon'] : 'comments',
                    'text' => trim((string) ($card['text'] ?? '')),
                    'href' => $href,
                    'link_label' => match ($type) {
                        'phone' => $phone,
                        'email' => $email,
                        default => trim((string) ($card['link_label'] ?? '')),
                    },
                    'hours' => ($card['show_hours'] ?? false) ? trim((string) ($footer['hours'] ?? '')) : '',
                ];
            })->values();
    }
}
