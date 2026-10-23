<?php

namespace App\Services\Integrations\Watchline;

use App\Services\Settings\SystemSettingsService;
use Cron\CronExpression;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class WatchlineIntegrationSettings
{
    public const JOBS = [
        'gath' => ['label' => 'GATH — cijene i zalihe', 'expression' => '7,37 * * * *'],
        'wolt_catalog' => ['label' => 'Wolt — cijene i dostupnost', 'expression' => '12,32,52 * * * *'],
        'wolt_inventory' => ['label' => 'Wolt — količine', 'expression' => '15,35,55 * * * *'],
        'reviews' => ['label' => 'Pozivi za recenzije', 'expression' => '22 11 * * *'],
        'abandoned' => ['label' => 'Napuštene košarice', 'expression' => '21 3,7,11,15,19,23 * * *'],
    ];

    public function __construct(private readonly SystemSettingsService $settings) {}

    public function options(): array
    {
        $defaults = [
            'live_enabled' => false,
            'gath_csv_path' => storage_path('app/private/watchline/gath/webstanje.csv'),
            'gath_reset_missing' => false,
            'schedules' => array_map(fn (array $job): array => ['enabled' => false, 'expression' => $job['expression']], self::JOBS),
        ];

        return array_replace_recursive($defaults, (array) $this->settings->get('watchline_integrations', []));
    }

    public function save(array $options): void
    {
        $rules = [
            'live_enabled' => ['required', 'boolean'],
            'gath_csv_path' => ['required', 'string', 'max:2048'],
            'gath_reset_missing' => ['required', 'boolean'],
        ];
        foreach (self::JOBS as $job => $definition) {
            $rules['schedules.'.$job.'.enabled'] = ['required', 'boolean'];
            $rules['schedules.'.$job.'.expression'] = ['required', 'string', 'max:100'];
        }
        $validated = Validator::make($options, $rules)->validate();
        foreach ($validated['schedules'] as $job => &$schedule) {
            $schedule['expression'] = trim($schedule['expression']);
            if (! CronExpression::isValidExpression($schedule['expression'])) {
                throw ValidationException::withMessages(['form.schedules.'.$job.'.expression' => 'Unesite valjan cron raspored s pet polja.']);
            }
        }
        unset($schedule);
        $validated['gath_csv_path'] = trim($validated['gath_csv_path']);
        $this->settings->put('watchline_integrations', $validated);
    }

    public function liveReady(): bool
    {
        return config('storefront.home') === 'watchline'
            && ! app()->environment('local')
            && ! (bool) config('commerce.local_safe_mode', false)
            && (bool) $this->options()['live_enabled'];
    }
}
