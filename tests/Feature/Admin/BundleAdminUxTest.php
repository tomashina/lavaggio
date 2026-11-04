<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Catalog\Bundle\Manager;
use App\Models\Catalog\Bundle\Bundle;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\CustomerGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class BundleAdminUxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'lavaggio', 'app.locale' => 'hr', 'app.fallback_locale' => 'hr']);
        $user = User::factory()->create();
        Bouncer::assign('superadmin')->to($user);
        $this->actingAs($user);
    }

    public function test_bundle_and_promotions_pages_highlight_only_the_current_navigation_link(): void
    {
        app(\App\Services\Settings\SystemSettingsService::class)->put('catalog_use_actions', true);
        foreach (['admin.bundles', 'admin.actions'] as $route) {
            $document = new \DOMDocument;
            @$document->loadHTML('<?xml encoding="UTF-8">'.$this->get(route($route))->assertOk()->getContent());
            $links = (new \DOMXPath($document))->query('//*[@id="admin-sidebar"]//a[contains(@class, "is-active-leaf")]');
            $this->assertCount(1, $links);
            $this->assertSame(route($route), $links->item(0)->getAttribute('href'));
        }
    }

    public function test_status_filters_search_and_reset_keep_the_list_consistent(): void
    {
        Bundle::create(['title' => 'Aktivan komplet', 'is_active' => true]);
        Bundle::create(['title' => 'Zakazan komplet', 'is_active' => true, 'starts_at' => now()->addDay()]);
        Bundle::create(['title' => 'Istekao komplet', 'is_active' => true, 'ends_at' => now()->subDay()]);
        Bundle::create(['title' => 'Isključen komplet', 'is_active' => false]);

        $component = Livewire::test(Manager::class)->set('statusFilter', 'active')
            ->assertSee('Aktivan komplet')->assertDontSee('Zakazan komplet')->assertDontSee('Istekao komplet')->assertDontSee('Isključen komplet');
        $component->set('statusFilter', 'scheduled')->assertSee('Zakazan komplet')->assertDontSee('Aktivan komplet');
        $component->set('statusFilter', 'expired')->assertSee('Istekao komplet')->assertDontSee('Zakazan komplet');
        $component->set('statusFilter', 'disabled')->assertSee('Isključen komplet')->assertDontSee('Aktivan komplet');
        $component->call('clearFilters')->assertSet('search', '')->assertSet('statusFilter', 'all')->assertSee('Aktivan komplet');
        $component->call('setPage', 2)->set('search', '  Zakazan  ')->assertSet('paginators.page', 1)
            ->assertSee('Zakazan komplet')->assertDontSee('Aktivan komplet');
        $component->set('search', 'Nema takve ponude')->assertSee('Nema ponuda za odabrane filtre.')
            ->call('clearFilters')->assertSee('Aktivan komplet');
    }

    public function test_editor_searches_codes_excludes_selected_items_and_saves_the_chosen_quantities_and_discount(): void
    {
        $group = CustomerGroup::create(['code' => 'bundle-retail', 'name' => 'Kupci', 'is_active' => true, 'is_default' => true]);
        $first = $this->product('ARIEL-SEARCH', 'Ariel njega');
        $second = $this->product('LENOR-SEARCH', 'Lenor miris');

        $component = Livewire::test(Manager::class)->call('create')
            ->assertSee('Još nema artikala u kompletu.')
            ->set('productSearch', ' ARIEL-SEARCH ')
            ->assertViewHas('productResults', fn ($products) => $products->modelKeys() === [$first->id])
            ->call('addProduct', $first->id)->assertSet('productSearch', '')
            ->call('addProduct', $first->id)->assertSet('products', [['product_id' => $first->id, 'quantity' => 1]])
            ->set('productSearch', 'ARIEL-SEARCH')
            ->assertViewHas('productResults', fn ($products) => $products->isEmpty())
            ->call('addProduct', $second->id)
            ->set('title', '  Svježe rublje  ')->set('isActive', true)
            ->set('products.0.quantity', 2)->set('products.1.quantity', 3)
            ->set('discounts.0.value', 15)
            ->call('save')->assertHasNoErrors()->assertSet('editing', false)
            ->assertSee('Bundle ponuda je spremljena.');

        $bundle = Bundle::where('title', 'Svježe rublje')->sole();
        $this->assertSame(2, (int) $bundle->products()->find($first->id)->pivot->quantity);
        $this->assertSame(3, (int) $bundle->products()->find($second->id)->pivot->quantity);
        $this->assertSame($group->id, $bundle->discounts()->sole()->customer_group_id);
        $this->assertSame(15.0, (float) $bundle->discounts()->sole()->value);
        $component->call('edit', $bundle->id)->assertSet('title', 'Svježe rublje')
            ->set('title', 'Uređen komplet')->call('save')->assertHasNoErrors();
        $this->assertSame('Uređen komplet', $bundle->fresh()->title);
        $this->assertCount(2, $bundle->fresh()->products);
    }

    public function test_inline_validation_and_cancel_do_not_save_partial_changes(): void
    {
        Livewire::test(Manager::class)->call('create')->call('save')
            ->assertHasErrors(['title', 'products', 'discounts'])
            ->assertSee('Ponuda još nije spremljena. Provjerite označena polja.')
            ->assertSee('Dodajte najmanje dva različita artikla u komplet.')
            ->set('title', 'Nespremljena ponuda')->set('productSearch', 'Ariel')
            ->call('cancel')->assertSet('editing', false)->assertSet('editingId', null)
            ->assertSet('title', '')->assertSet('productSearch', '')->assertHasNoErrors();
        $this->assertDatabaseCount('catalog_bundles', 0);
    }

    public function test_view_only_staff_are_not_shown_unavailable_mutation_actions(): void
    {
        $user = User::factory()->create();
        Bouncer::allow($user)->to('catalog.actions.view');
        Bundle::create(['title' => 'Komplet samo za pregled', 'is_active' => true]);

        Livewire::actingAs($user)->test(Manager::class)
            ->assertSee('Komplet samo za pregled')
            ->assertDontSeeHtml('wire:click="create"')
            ->assertDontSeeHtml('wire:click="edit(')
            ->assertDontSeeHtml('wire:click="delete(')
            ->assertDontSee('Upravljanje');
    }

    public function test_editing_preserves_an_assigned_inactive_group_and_rejects_an_invalid_percentage(): void
    {
        $group = CustomerGroup::create(['code' => 'bundle-inactive', 'name' => 'Sačuvana grupa', 'is_active' => false]);
        $first = $this->product('FIRST-KEEP', 'Prvi artikl');
        $second = $this->product('SECOND-KEEP', 'Drugi artikl');
        $bundle = Bundle::create(['title' => 'Sačuvana ponuda', 'is_active' => true, 'payload' => ['custom' => 'preserved']]);
        $bundle->products()->attach([$first->id => ['quantity' => 2], $second->id => ['quantity' => 1]]);
        $bundle->discounts()->create(['customer_group_id' => $group->id, 'type' => 'percent', 'value' => 12]);

        Livewire::test(Manager::class)->call('edit', $bundle->id)
            ->assertViewHas('groups', fn ($groups) => $groups->contains('id', $group->id))
            ->set('discounts.0.value', 101)->call('save')->assertHasErrors('discounts.0.value')
            ->assertSee('Postotak mora biti između 0 i 100.')
            ->set('discounts.0.value', 20)->call('save')->assertHasNoErrors();
        $this->assertSame($group->id, $bundle->fresh()->discounts()->sole()->customer_group_id);
        $this->assertSame('preserved', $bundle->fresh()->payload['custom']);
        $this->assertSame(2, (int) $bundle->products()->find($first->id)->pivot->quantity);
    }

    public function test_lavaggio_schedule_round_trips_zagreb_time_across_the_daylight_saving_transition(): void
    {
        config(['app.timezone' => 'UTC']);
        $group = CustomerGroup::create(['code' => 'bundle-dates', 'name' => 'Kupci', 'is_active' => true]);
        $first = $this->product('DATE-FIRST', 'Prvi artikl');
        $second = $this->product('DATE-SECOND', 'Drugi artikl');
        $bundle = Bundle::create(['title' => 'Proljetni komplet', 'is_active' => true,
            'starts_at' => '2027-03-27 09:00:00', 'ends_at' => '2027-03-29 08:00:00']);
        $bundle->products()->attach([$first->id => ['quantity' => 1], $second->id => ['quantity' => 1]]);
        $bundle->discounts()->create(['customer_group_id' => $group->id, 'type' => 'percent', 'value' => 10]);

        $component = Livewire::test(Manager::class)
            ->assertSee('Od 27.03.2027. 10:00')->assertSee('Do 29.03.2027. 10:00')
            ->call('edit', $bundle->id)
            ->assertSet('startsAt', '2027-03-27T10:00')->assertSet('endsAt', '2027-03-29T10:00')
            ->call('save')->assertHasNoErrors();
        $this->assertSame('2027-03-27 09:00:00', $bundle->fresh()->getRawOriginal('starts_at'));
        $this->assertSame('2027-03-29 08:00:00', $bundle->fresh()->getRawOriginal('ends_at'));

        $component->call('edit', $bundle->id)->set('startsAt', '2027-03-27T11:30')
            ->set('endsAt', '2027-03-29T11:30')->call('save')->assertHasNoErrors();
        $this->assertSame('2027-03-27 10:30:00', $bundle->fresh()->getRawOriginal('starts_at'));
        $this->assertSame('2027-03-29 09:30:00', $bundle->fresh()->getRawOriginal('ends_at'));
    }

    public function test_other_storefronts_keep_application_timezone_in_bundle_dates(): void
    {
        config(['storefront.home' => 'auto', 'app.timezone' => 'UTC']);
        $bundle = Bundle::create(['title' => 'Druga trgovina', 'is_active' => true, 'starts_at' => '2027-07-01 09:00:00']);

        Livewire::test(Manager::class)->assertSee('Od 01.07.2027. 09:00')
            ->call('edit', $bundle->id)->assertSet('startsAt', '2027-07-01T09:00');
    }

    private function product(string $code, string $name): Product
    {
        $product = Product::create(['code' => $code, 'sku' => 'sku-'.substr(md5($code), 0, 8), 'base_price' => 10, 'stock_qty' => 20, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => $name, 'slug' => strtolower($code)]);

        return $product;
    }
}
