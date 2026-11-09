<?php

namespace App\Livewire\Admin\Integrations\Fira;

use App\Services\Integrations\Fira\FiraSettingsService;
use Livewire\Component;

class Dashboard extends Component
{
    public array $form = [];

    public string $secret = '';

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
        ]);
    }

    private function authorizeManage(): void
    {
        $user = auth()->user();
        abort_unless(config('storefront.home') === 'lavaggio' && $user
            && ($user->isA('superadmin') || $user->can('admin.access') && $user->can('settings.api.manage')), 403);
    }
}
