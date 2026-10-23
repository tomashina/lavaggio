<?php

namespace App\Livewire\Admin\Integrations\Watchline;

use App\Models\Integrations\WatchlineIntegrationRun;
use App\Models\Settings\Local\OrderStatus;
use App\Services\Integrations\Watchline\GathSyncService;
use App\Services\Integrations\Watchline\WatchlineIntegrationService;
use App\Services\Integrations\Watchline\WatchlineIntegrationSettings;
use App\Services\Integrations\Watchline\WatchlineMessageAutomationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Throwable;

class Dashboard extends Component
{
    use WithFileUploads;

    public array $form = [];

    public array $messages = [];

    public $csvUpload;

    #[Locked]
    public array $report = [];

    #[Locked]
    public ?string $gathPreviewChecksum = null;

    #[Locked]
    public ?string $gathPreviewConfigurationHash = null;

    public function mount(WatchlineIntegrationSettings $settings, WatchlineMessageAutomationService $messages): void
    {
        $this->authorizeManage();
        $this->form = $settings->options();
        foreach (['reviews', 'abandoned'] as $job) {
            $this->messages[$job] = $messages->options($job);
        }
    }

    public function save(WatchlineIntegrationSettings $settings, WatchlineMessageAutomationService $messages): void
    {
        $this->authorizeManage();
        $this->validate([
            'messages.reviews.enabled' => ['required', 'boolean'],
            'messages.reviews.delay_days' => ['required', 'integer', 'min:1', 'max:90'],
            'messages.reviews.status_ids' => ($this->messages['reviews']['enabled'] ?? false)
                ? ['required', 'array', 'min:1'] : ['present', 'array'],
            'messages.reviews.status_ids.*' => ['integer', 'exists:order_statuses,id'],
            'messages.abandoned.enabled' => ['required', 'boolean'],
            'messages.abandoned.delay_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
        ]);
        // A later validation failure must not leave partially changed provider settings.
        DB::transaction(function () use ($messages, $settings): void {
            foreach (['reviews', 'abandoned'] as $job) {
                $this->messages[$job] = $messages->configure($job, $this->messages[$job]);
            }
            $settings->save($this->form);
        });
        $this->form = $settings->options();
        $this->gathPreviewChecksum = null;
        $this->gathPreviewConfigurationHash = null;
        $this->report = [];
        $this->dispatch('notify', type: 'success', message: 'Postavke integracija su spremljene.');
    }

    public function uploadCsv(WatchlineIntegrationSettings $settings, GathSyncService $gath): void
    {
        $this->authorizeManage();
        $this->validate(['csvUpload' => ['required', 'file', 'max:10240', 'extensions:csv,txt']]);
        $path = $this->csvUpload->storeAs('watchline/gath/uploads', Str::uuid().'.csv', 'local');
        $absolute = Storage::disk('local')->path($path);
        @chmod($absolute, 0600);
        try {
            $gath->preview($absolute);
        } catch (Throwable) {
            Storage::disk('local')->delete($path);
            $this->addError('csvUpload', 'Datoteka nije valjan GATH izvještaj. Očekuju se šifra, količina, cijena i brand odvojeni točkom sa zarezom.');

            return;
        }
        $saved = $settings->options();
        $saved['gath_csv_path'] = $absolute;
        $settings->save($saved);
        $this->form['gath_csv_path'] = $absolute;
        $this->csvUpload = null;
        $this->gathPreviewChecksum = null;
        $this->gathPreviewConfigurationHash = null;
        $this->report = [];
        $this->dispatch('notify', type: 'success', message: 'GATH datoteka je spremljena. Pokrenite pregled promjena.');
    }

    public function previewJob(string $job, WatchlineIntegrationService $integrations, WatchlineIntegrationSettings $settings): void
    {
        $this->authorizeManage();
        abort_unless(isset(WatchlineIntegrationSettings::JOBS[$job]), 404);
        if ($job === 'gath') {
            $this->resetValidation('gath');
        }
        $this->report = $integrations->run($job, true, 'manual', (int) auth()->id());
        $this->gathPreviewChecksum = $job === 'gath' && ! empty($this->report['can_apply'])
            ? ($this->report['checksum'] ?? null) : null;
        $this->gathPreviewConfigurationHash = $this->gathPreviewChecksum !== null
            ? $this->gathConfigurationHash($settings->options()) : null;
        $this->dispatch('watchline-integration-report');
    }

    public function applyJob(string $job, WatchlineIntegrationService $integrations, WatchlineIntegrationSettings $settings, GathSyncService $gath): void
    {
        $this->authorizeManage();
        abort_unless(isset(WatchlineIntegrationSettings::JOBS[$job]), 404);
        if ($job === 'gath') {
            $configuration = $settings->options();
            try {
                $current = $gath->preview($configuration['gath_csv_path']);
            } catch (Throwable) {
                $current = [];
            }
            if ($this->gathPreviewChecksum === null || ! hash_equals($this->gathPreviewChecksum, (string) ($current['checksum'] ?? ''))
                || $this->gathPreviewConfigurationHash === null || ! hash_equals($this->gathPreviewConfigurationHash, $this->gathConfigurationHash($configuration))) {
                $this->gathPreviewChecksum = null;
                $this->gathPreviewConfigurationHash = null;
                $this->addError('gath', 'Prvo pregledajte aktualnu datoteku. Ako se datoteka ili postavke izvora promijene, potreban je novi pregled.');

                return;
            }
            $this->resetValidation('gath');
        }
        $this->report = $integrations->run($job, false, 'manual', (int) auth()->id(), $job === 'gath' ? $this->gathPreviewChecksum : null);
        $this->gathPreviewChecksum = null;
        $this->gathPreviewConfigurationHash = null;
        $this->dispatch('watchline-integration-report');
    }

    public function render(WatchlineIntegrationSettings $settings)
    {
        $this->authorizeManage();

        return view('livewire.admin.integrations.watchline.dashboard', [
            'jobs' => WatchlineIntegrationSettings::JOBS,
            'runs' => WatchlineIntegrationRun::query()->latest('id')->limit(20)->get(),
            'orderStatuses' => OrderStatus::query()->orderBy('id')->get(['id', 'name']),
            'liveReady' => $settings->liveReady(),
            'localPreview' => app()->environment('local') || (bool) config('commerce.local_safe_mode', false),
        ]);
    }

    private function authorizeManage(): void
    {
        abort_unless(config('storefront.home') === 'watchline' && auth()->check()
            && (Bouncer::is(auth()->user())->a('superadmin') || auth()->user()->can('integrations.stock.manage')), 403);
    }

    private function gathConfigurationHash(array $options): string
    {
        return hash('sha256', json_encode([(string) $options['gath_csv_path'], (bool) $options['gath_reset_missing']], JSON_THROW_ON_ERROR));
    }
}
