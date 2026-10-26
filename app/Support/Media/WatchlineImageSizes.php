<?php

namespace App\Support\Media;

use App\Services\Front\NavigationMenuService;

/** Source-selection hints only: these never change the rendered layout. */
final class WatchlineImageSizes
{
    public static function carousel(): string
    {
        return '(max-width: 639px) '.self::cell(2, 32).', (max-width: 767px) '.self::cell(2)
            .', (max-width: 1279px) '.self::cell(4).', '.self::cell(6);
    }

    public static function catalog(int $columns, int $mobileColumns = 2, bool $sidebar = true): string
    {
        $columns = in_array($columns, [1, 2, 3, 4, 5], true) ? $columns : 4;
        $mobileColumns = $mobileColumns === 1 ? 1 : 2;
        $tablet = $columns <= 2 ? $columns : 2;
        $desktop = min($columns, 3);
        $large = min($columns, 4);
        $sidebarSpace = $sidebar ? 288 : 0; // 272px sidebar plus its 16px gap.

        return '(max-width: 639px) '.self::cell($mobileColumns, 32)
            .', (max-width: 767px) '.self::cell($mobileColumns)
            .', (max-width: 1023px) '.self::cell($tablet)
            .', (max-width: 1024px) '.self::cell($desktop)
            .', (max-width: 1279px) '.self::cell($desktop, 48 + $sidebarSpace)
            .', (max-width: 1535px) '.self::cell($large, 48 + $sidebarSpace)
            .', '.self::cell($columns, 48 + $sidebarSpace);
    }

    public static function giftGuide(int $columns): string
    {
        $columns = in_array($columns, [3, 4, 5], true) ? $columns : 5;

        return '(max-width: 639px) '.self::cell(2, 32).', (max-width: 767px) '.self::cell(2)
            .', (max-width: 1100px) '.self::cell(3).', '.self::cell($columns);
    }

    public static function campaign(string $kind, array $image = []): string
    {
        $ratio = (float) ($image['width'] ?? 0) > 0 && (float) ($image['height'] ?? 0) > 0
            ? $image['width'] / $image['height'] : match ($kind) {
                'cluse' => 1, 'mens' => 698 / 931, default => 900 / 506
            };
        $paintWidth = (int) ceil(($kind === 'cluse' ? 330 : 310) * $ratio);
        if ($kind === 'cluse') {
            // Square artwork is contained by a 330px-high frame (250px mobile).
            return '(max-width: 639px) 250px, (max-width: 767px) min('.$paintWidth.'px, calc((100vw - 106px) * 0.56)), min('.$paintWidth.'px, calc((min(100vw, '.self::maximum().'px) - 182px) * 0.28))';
        }
        if ($kind === 'mens') {
            // Portrait artwork is contained by a maximum 310px-high frame.
            return '(max-width: 639px) 42vw, (max-width: 767px) min('.$paintWidth.'px, 45vw), min('.$paintWidth.'px, calc((min(100vw, '.self::maximum().'px) - 70px) * 0.225))';
        }

        return '(max-width: 639px) 48vw, (max-width: 767px) min('.$paintWidth.'px, 51vw), min('.$paintWidth.'px, calc((min(100vw, '.self::maximum().'px) - 70px) * 0.255))';
    }

    private static function cell(int $columns, int $deduct = 48): string
    {
        return 'calc((min(100vw, '.self::maximum().'px) - '.$deduct.'px) / '.$columns.')';
    }

    private static function maximum(): int
    {
        return (int) app(NavigationMenuService::class)->appearance()['container_width'];
    }
}
