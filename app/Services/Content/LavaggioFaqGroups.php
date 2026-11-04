<?php

namespace App\Services\Content;

use App\Models\Content\Support\Faq;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class LavaggioFaqGroups
{
    public const SETTING = 'lavaggio_faq_groups';

    public function __construct(private readonly SystemSettingsService $settings) {}

    public function all(): array
    {
        $stored = $this->settings->get(self::SETTING, null);
        $groups = is_array($stored) ? $stored : [
            ['code' => 'narucivanje', 'title' => 'Pitanja o naručivanju', 'sort_order' => 0],
            ['code' => 'lavaggio', 'title' => 'Pitanja o praonici', 'sort_order' => 10],
            ['code' => 'general', 'title' => 'Opća pitanja', 'sort_order' => 20],
        ];
        $groups = collect($groups)->filter(fn ($group) => is_array($group) && trim((string) ($group['code'] ?? '')) !== '')->keyBy('code');
        foreach (Faq::query()->distinct()->pluck('group_code') as $code) {
            $code = trim((string) $code) ?: 'general';
            if (! $groups->has($code)) {
                $groups->put($code, ['code' => $code, 'title' => Str::headline($code), 'sort_order' => 100]);
            }
        }

        return $groups->sortBy('sort_order')->values()->all();
    }

    public function sections(Collection $faqs): Collection
    {
        $questions = $faqs->groupBy(fn (Faq $faq) => trim((string) $faq->group_code) ?: 'general');

        return collect($this->all())
            ->filter(fn ($group) => $questions->has($group['code']))
            ->map(fn ($group) => ['code' => $group['code'], 'title' => $group['title'], 'faqs' => $questions->get($group['code'])])
            ->values();
    }
}
