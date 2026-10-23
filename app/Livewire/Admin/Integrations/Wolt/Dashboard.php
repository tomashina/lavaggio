<?php

namespace App\Livewire\Admin\Integrations\Wolt;

use App\Models\Catalog\Product\Product;
use App\Services\Integrations\Wolt\WoltPayloadService;
use App\Services\Integrations\Wolt\WoltSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Silber\Bouncer\BouncerFacade as Bouncer;

class Dashboard extends Component
{
    public array $form = [];

    public array $credentials = ['username' => '', 'password' => '', 'access_token' => ''];

    public string $productSearch = '';

    public ?array $preview = null;

    public function mount(WoltSettingsService $settings): void
    {
        $this->authorizeManage();
        $this->form = $settings->options();
    }

    public function save(WoltSettingsService $settings): void
    {
        $this->authorizeManage();
        $this->validate([
            'credentials.username' => ['nullable', 'string', 'max:1024'],
            'credentials.password' => ['nullable', 'string', 'max:4096'],
            'credentials.access_token' => ['nullable', 'string', 'max:8192'],
        ]);
        $settings->save($this->form, $this->credentials);
        $this->form = $settings->options();
        $this->credentials = ['username' => '', 'password' => '', 'access_token' => ''];
        $this->preview = null;
        $this->dispatch('notify', type: 'success', message: __('Wolt postavke su spremljene.'));
    }

    public function addProduct(int $productId): void
    {
        $this->authorizeManage();
        Product::query()->findOrFail($productId);
        $this->form['product_ids'] = array_values(array_unique([...($this->form['product_ids'] ?? []), $productId]));
        $this->productSearch = '';
        $this->preview = null;
    }

    public function removeProduct(int $productId): void
    {
        $this->authorizeManage();
        $this->form['product_ids'] = array_values(array_filter(
            (array) ($this->form['product_ids'] ?? []),
            static fn ($id): bool => (int) $id !== $productId,
        ));
        $this->preview = null;
    }

    public function previewPayload(string $operation, WoltPayloadService $payloads): void
    {
        $this->authorizeManage();
        // Preview uses saved settings, so an unsaved edit cannot accidentally change a later run.
        $result = $payloads->preview($operation);
        $result['payload']['data'] = array_slice($result['payload']['data'], 0, 25);
        $this->preview = $result;
    }

    public function render(WoltSettingsService $settings)
    {
        $this->authorizeManage();
        $search = mb_substr(trim($this->productSearch), 0, 100);
        $searchProducts = collect();
        if (mb_strlen($search) >= 2) {
            $searchProducts = Product::query()->with('translations')
                ->where(function (Builder $query) use ($search): void {
                    $query->where('code', 'like', '%'.$search.'%')
                        ->orWhere('sku', 'like', '%'.$search.'%')
                        ->orWhereHas('translations', fn (Builder $translations) => $translations->where('name', 'like', '%'.$search.'%'));
                })->orderBy('code')->limit(20)->get();
        }

        return view('livewire.admin.integrations.wolt.dashboard', [
            'credentialsConfigured' => $settings->credentialsConfigured(),
            'localPreviewOnly' => $settings->localPreviewOnly(),
            'blockers' => $settings->sendBlockers(),
            'sourceProductCount' => Product::query()->whereNotNull('payload->opencart->product_id')->count(),
            'searchProducts' => $searchProducts,
            'selectedProducts' => Product::query()->with('translations')->whereIn('id', (array) ($this->form['product_ids'] ?? []))->orderBy('code')->get(),
        ]);
    }

    private function authorizeManage(): void
    {
        $user = auth()->user();
        abort_unless(config('storefront.home') === 'watchline'
            && $user && (Bouncer::is($user)->an('superadmin') || $user->can('integrations.stock.manage')), 403);
    }
}
