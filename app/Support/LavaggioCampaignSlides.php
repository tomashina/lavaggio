<?php

namespace App\Support;

class LavaggioCampaignSlides
{
    public const LIMIT = 12;

    /** Keep disabled slides in the editor; filter only when rendering. */
    public static function normalize(mixed $value, ?bool $shippingNoticeDefault = null): array
    {
        $slides = [];
        foreach (array_slice(is_array($value) ? $value : [], 0, self::LIMIT) as $slide) {
            if (! is_array($slide)) {
                continue;
            }
            $row = [];
            foreach (['eyebrow', 'title', 'subtitle', 'cta_label', 'image_alt'] as $key) {
                $row[$key] = is_scalar($slide[$key] ?? null) ? (string) $slide[$key] : '';
            }
            $row['image'] = WatchlineWidgetSettings::safeUrl($slide['image'] ?? '', true);
            $row['url'] = WatchlineWidgetSettings::safeUrl($slide['url'] ?? '');
            $row['display'] = ($slide['display'] ?? '') === 'image' ? 'image' : 'text_image';
            $row['enabled'] = filter_var($slide['enabled'] ?? true, FILTER_VALIDATE_BOOL);
            if (array_key_exists('shipping_notice', $slide) || $shippingNoticeDefault !== null) {
                $row['shipping_notice'] = array_key_exists('shipping_notice', $slide)
                    ? filter_var($slide['shipping_notice'], FILTER_VALIDATE_BOOL)
                    : ($row['display'] === 'image' ? false : $shippingNoticeDefault);
            }
            $slides[] = $row;
        }

        return $slides;
    }

    public static function legacy(array $settings): array
    {
        return self::normalize([[
            'eyebrow' => $settings['eyebrow'] ?? '', 'title' => $settings['title'] ?? '',
            'subtitle' => $settings['subtitle'] ?? '', 'cta_label' => $settings['cta_label'] ?? '',
            'url' => $settings['cta_url'] ?? '', 'image' => $settings['main_image'] ?? '',
            'image_alt' => $settings['main_image_alt'] ?? '',
            'display' => $settings['slide_display'] ?? 'text_image', 'enabled' => true,
        ]], filter_var($settings['shipping_notice'] ?? true, FILTER_VALIDATE_BOOL))[0];
    }

    public static function visible(array $settings): array
    {
        $slides = self::normalize($settings['slides'] ?? [], filter_var($settings['shipping_notice'] ?? true, FILTER_VALIDATE_BOOL));
        if ($slides === []) {
            $slides = [self::legacy($settings)];
        }

        return array_values(array_filter($slides, static fn (array $slide): bool => $slide['enabled']
            && ($slide['display'] === 'image' ? $slide['image'] !== '' : trim($slide['title'].$slide['subtitle'].$slide['image']) !== '')));
    }
}
