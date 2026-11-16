<?php

namespace App\Livewire\Admin\Integrations\Fira;

use App\Services\Integrations\Fira\FiraPriceSyncService;
use App\Services\Integrations\Fira\FiraSettingsService;
use App\Services\Settings\SystemSettingsService;
use Livewire\Component;

class Dashboard extends Component
{
    public array $form = [];

    public string $secret = '';

    public ?array $syncResult = null;

    public function mount(FiraSettingsService $settings): void
    {
        $this->authorizeManage();
        $this->form = $settings->options();
    }

    public function save(FiraSettingsService $settings): void
    {
        $this->authorizeManage();
        $settings->save($this->form, $this->secret);
        $this->secret = '';
        $this->form = $settings->options();
        $this->dispatch('notify', type: 'success', message: 'FIRA postavke su spremljene. Nijedan dokument nije poslan.');
    }

    public function render(FiraSettingsService $settings)
    {
        $this->authorizeManage();

        return view('livewire.admin.integrations.fira.dashboard', [
            'secretConfigured' => $settings->secret() !== '',
            'blockers' => $settings->blockers(),
            'lastSync' => app(SystemSettingsService::class)->get('lavaggio_fira_price_sync'),
        ]);
    }

    public function syncPrices(FiraPriceSyncService $sync): void
    {
        $this->authorizeManage();
        $this->resetErrorBag('fira_sync');
        $this->syncResult = $sync->sync(true);
        $this->dispatch('notify', type: 'success', message: 'Dohvat cijena je završen. Izmijenjeno: '.$this->syncResult['changed'].'.');
    }

    private function authorizeManage(): void
    {
        $user = auth()->user();
        abort_unless(config('storefront.home') === 'lavaggio' && $user
            && ($user->isA('superadmin') || $user->can('admin.access') && $user->can('settings.api.manage')), 403);
    }
}
