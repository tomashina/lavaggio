<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Settings\Local\ResourceManager;
use App\Models\Settings\Local\OrderStatus;
use App\Models\User;
use App\Support\OrderStatusClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class LavaggioOrderStatusLocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['storefront.home' => 'lavaggio', 'app.locale' => 'hr']);
        app()->setLocale('hr');
    }

    public function test_imported_statuses_display_in_croatian_without_changing_the_stored_names(): void
    {
        $labels = [
            'Pending' => 'Na čekanju',
            'Processing' => 'U obradi',
            'Shipped' => 'Poslano',
            'Complete' => 'Dovršeno',
            'Canceled' => 'Otkazano',
            'Cancelled' => 'Otkazano',
            'Denied' => 'Odbijeno',
            'Canceled Reversal' => 'Poništen povrat transakcije',
            'Failed' => 'Neuspjelo',
            'Refunded' => 'Vraćen novac',
            'Reversed' => 'Stornirana transakcija',
            'Chargeback' => 'Povrat po prigovoru',
            'Expired' => 'Isteklo',
            'Processed' => 'Obrađeno',
            'Voided' => 'Poništeno',
            'New' => 'Novo',
            'Paid' => 'Plaćeno',
            'Sent' => 'Poslano',
            'Completed' => 'Dovršeno',
        ];
        $expected = [];
        foreach ($labels as $source => $label) {
            $status = $this->makeStatus('legacy-'.count($expected), $source);
            $this->assertSame($label, $status->name);
            $this->assertSame($source, $status->getRawOriginal('name'));
            $expected[$status->id] = $label;
        }

        // Customer order/history relations and payment dropdowns omit the code.
        $this->assertSame($expected, OrderStatus::query()->orderBy('id')->get(['id', 'name'])->pluck('name', 'id')->all());
        $this->assertSame($expected, OrderStatus::query()->orderBy('id')->pluck('name', 'id')->all());

        $response = $this->actingAs($this->makeAdmin())
            ->get(route('admin.settings.local.resource', ['resource' => 'order-statuses']))
            ->assertOk()
            ->assertSeeText('Postavke / Lokalno / Statusi narudžbi');
        foreach ($labels as $source => $label) {
            $response->assertSeeText($label);
            $this->assertDatabaseHas('order_statuses', ['name' => $source]);
        }
    }

    public function test_admin_can_search_translated_labels_source_names_and_status_codes(): void
    {
        $processing = $this->makeStatus('herrera-oc-status-2', 'Processing');
        $pending = $this->makeStatus('herrera-oc-status-1', 'Pending');
        $this->makeStatus('custom-ready', 'Spremno za preuzimanje');

        $component = Livewire::actingAs($this->makeAdmin())
            ->test(ResourceManager::class, ['resource' => 'order-statuses']);

        foreach (['obradi', 'U obradi', 'Processing', $processing->code] as $search) {
            $component->set('search', $search)
                ->assertSeeText('U obradi')
                ->assertDontSeeText('Na čekanju')
                ->assertDontSeeText('Spremno za preuzimanje');
        }

        $component->set('search', 'čekanju')
            ->assertSeeText('Na čekanju')
            ->assertDontSeeText('U obradi');
        $component->set('search', 'preuzimanje')
            ->assertSeeText('Spremno za preuzimanje')
            ->assertDontSeeText('Na čekanju');

        $this->assertSame('Processing', $processing->fresh()->getRawOriginal('name'));
        $this->assertSame('Pending', $pending->fresh()->getRawOriginal('name'));

        $pending->update(['name' => 'Na čekanju']);
        foreach (['čekanju', 'CEKANJU'] as $search) {
            $component->set('search', $search)
                ->assertSeeText('Na čekanju')
                ->assertDontSeeText('U obradi');
        }
    }

    public function test_editor_saves_the_displayed_name_and_allows_custom_names_without_changing_status_configuration(): void
    {
        $admin = $this->makeAdmin();
        $settings = ['source' => 'opencart', 'opencart' => ['order_status_id' => 2, 'name' => 'Processing']];
        $status = $this->makeStatus('herrera-oc-status-2', 'Processing', [
            'description' => 'Imported payment status',
            'color' => 'blue',
            'is_default' => true,
            'is_paid' => true,
            'is_cancelled' => false,
            'is_active' => true,
            'sort_order' => 7,
            'settings' => $settings,
        ]);

        Livewire::actingAs($admin)->test(ResourceManager::class, [
            'resource' => 'order-statuses',
            'recordId' => $status->id,
            'editPage' => true,
        ])->assertSet('form.name', 'U obradi')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('U obradi', $status->fresh()->getRawOriginal('name'));

        Livewire::actingAs($admin)->test(ResourceManager::class, [
            'resource' => 'order-statuses',
            'recordId' => $status->id,
            'editPage' => true,
        ])->set('form.name', 'Spremno za osobno preuzimanje')
            ->call('save')
            ->assertHasNoErrors();

        $status->refresh();
        $this->assertSame('Spremno za osobno preuzimanje', $status->name);
        $this->assertSame('Spremno za osobno preuzimanje', $status->getRawOriginal('name'));
        $this->assertSame('herrera-oc-status-2', $status->code);
        $this->assertSame('Imported payment status', $status->description);
        $this->assertSame('blue', $status->color);
        $this->assertTrue($status->is_default);
        $this->assertTrue($status->is_paid);
        $this->assertFalse($status->is_cancelled);
        $this->assertTrue($status->is_active);
        $this->assertSame(7, $status->sort_order);
        $this->assertSame($settings, $status->settings);
    }

    public function test_other_storefronts_and_english_locale_keep_the_original_labels(): void
    {
        $status = $this->makeStatus('herrera-oc-status-2', 'Processing');
        foreach (['auto', 'watchline', 'herrera'] as $home) {
            config(['storefront.home' => $home]);
            $this->assertSame('Processing', $status->fresh()->name);
        }

        config(['storefront.home' => 'lavaggio']);
        app()->setLocale('en');
        $this->assertSame('Processing', $status->fresh()->name);
        $this->assertSame([$status->id => 'Processing'], OrderStatus::query()->pluck('name', 'id')->all());
    }

    public function test_saving_the_translated_reversed_status_preserves_its_existing_cancellation_classification(): void
    {
        $status = $this->makeStatus('herrera-oc-status-12', 'Reversed', ['is_cancelled' => false]);
        $cancelled = $this->makeStatus('herrera-oc-status-7', 'Canceled', ['is_cancelled' => false]);

        Livewire::actingAs($this->makeAdmin())->test(ResourceManager::class, [
            'resource' => 'order-statuses',
            'recordId' => $status->id,
            'editPage' => true,
        ])->assertSet('form.name', 'Stornirana transakcija')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Stornirana transakcija', $status->fresh()->getRawOriginal('name'));
        $query = OrderStatus::query();
        OrderStatusClassification::applyCancelledQuery($query);
        $this->assertSame([$cancelled->id], $query->pluck('id')->all());
    }

    private function makeStatus(string $code, string $name, array $attributes = []): OrderStatus
    {
        return OrderStatus::query()->create(array_replace([
            'code' => $code,
            'name' => $name,
            'is_active' => true,
        ], $attributes));
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        Bouncer::assign('admin')->to($admin);

        return $admin;
    }
}
