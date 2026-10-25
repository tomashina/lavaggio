<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Catalog\Product\Form as ProductForm;
use App\Livewire\Admin\Catalog\Product\PromotionManager;
use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Services\Pricing\ProductPricePresentationService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class WatchlineProductPromotionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'watchline', 'app.locale' => 'hr', 'commerce.b2c_only' => true]);
        app()->setLocale('hr');
        app(SystemSettingsService::class)->put('catalog_use_actions', true);
    }

    public function test_product_tab_embeds_independent_promotion_manager_only_for_enabled_watchline(): void
    {
        $admin = $this->admin();
        $product = $this->product();
        $component = Livewire::actingAs($admin)->test(ProductForm::class, ['productId' => $product->id])
            ->assertSeeHtml("wire:click=\"setTab('promotion')\"")
            ->call('setTab', 'promotion')->assertSet('activeTab', 'promotion')
            ->assertSeeLivewire(PromotionManager::class)->assertDontSee(__('Update Product'));
        $this->assertSame(0, substr_count($component->html(), '<form wire:submit="save"'));
        Livewire::actingAs($admin)->test(ProductForm::class)->call('setTab', 'promotion')
            ->assertSee('Najprije spremite artikl kako biste dodali akciju.')->assertDontSeeLivewire(PromotionManager::class);

        config(['storefront.home' => 'auto']);
        Livewire::actingAs($admin)->test(ProductForm::class, ['productId' => $product->id])
            ->assertDontSeeHtml("wire:click=\"setTab('promotion')\"")->call('setTab', 'promotion')->assertSet('activeTab', 'content');
        config(['storefront.home' => 'watchline']);
        app(SystemSettingsService::class)->put('catalog_use_actions', false);
        Livewire::actingAs($admin)->test(ProductForm::class, ['productId' => $product->id])
            ->assertDontSeeHtml("wire:click=\"setTab('promotion')\"")->call('setTab', 'promotion')->assertSet('activeTab', 'content');
        Livewire::actingAs($admin)->test(PromotionManager::class, ['productId' => $product->id])->assertNotFound();
    }

    public function test_lists_all_matching_scopes_including_scheduled_actions_without_unrelated_actions(): void
    {
        $maker = Manufacturer::query()->create(['code' => 'guess', 'is_active' => true]);
        $product = $this->product(['manufacturer_id' => $maker->id]);
        $category = new Category(['code' => 'watches', 'scope' => Category::SCOPE_CATALOG, 'is_active' => true]);
        $category->saveAsRoot();
        $product->categories()->attach($category);
        $direct = $this->action($product, ['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(5)], 'Zakazana ponuda');
        $all = $this->action(null, ['target_type' => CatalogAction::TARGET_ALL], 'Globalna ponuda');
        $categoryAction = $this->action(null, ['target_type' => CatalogAction::TARGET_CATEGORY], 'Kategorijska ponuda');
        $categoryAction->targets()->create(['target_type' => CatalogAction::TARGET_CATEGORY, 'target_id' => $category->id]);
        $brandAction = $this->action(null, ['target_type' => CatalogAction::TARGET_MANUFACTURER], 'Ponuda branda');
        $brandAction->targets()->create(['target_type' => CatalogAction::TARGET_MANUFACTURER, 'target_id' => $maker->id]);
        $coupon = $this->action(null, ['target_type' => CatalogAction::TARGET_ALL, 'coupon_code' => 'SYNTHETIC10'], 'Kuponska ponuda');
        $cart = $this->action(null, ['target_type' => CatalogAction::TARGET_ALL, 'scope' => CatalogAction::SCOPE_CART, 'coupon_code' => 'NEWSLETTER10'], 'Newsletter kupon za košaricu');
        $this->action($this->product(['code' => 'other', 'sku' => 'OTHER']), [], 'Nevezana ponuda');

        $component = $this->manager($product)->assertSee('Zakazana ponuda')->assertSee('Zakazana')
            ->assertSee('Globalna ponuda')->assertSee('Kategorijska ponuda')->assertSee('Ponuda branda')
            ->assertSee('Kuponska ponuda')->assertSee('Kupon')->assertDontSee('Newsletter kupon za košaricu')
            ->assertDontSeeHtml(route('admin.actions.edit', ['action' => $cart->id, 'locale' => 'hr']))
            ->assertDontSee('Nevezana ponuda')->assertSeeHtml('wire:click="edit('.$direct->id.')"');
        foreach ([$all, $categoryAction, $brandAction, $coupon] as $shared) {
            $component->assertSeeHtml(route('admin.actions.edit', ['action' => $shared->id, 'locale' => 'hr']))
                ->assertDontSeeHtml('wire:click="edit('.$shared->id.')"');
        }
    }

    public function test_inline_edit_preserves_ids_targets_source_metadata_counters_and_other_translation_fields(): void
    {
        $product = $this->product(['payload' => ['opencart' => ['gallery_images' => ['catalog/watch.jpg']]]]);
        $action = $this->action($product, [
            'used_count' => 7, 'payload' => ['import_source' => 'watchline-opencart-special', 'source_special_id' => 99],
            'starts_at' => '2026-01-02 03:04:05', 'ends_at' => '2027-01-02 03:04:05',
        ]);
        $action->translations()->where('locale', 'hr')->update([
            'description' => 'Zadržani opis', 'badge' => 'Akcija', 'payload' => json_encode(['source' => 'retain']),
        ]);
        $action->translations()->create(['locale' => 'en', 'title' => 'Original title', 'description' => 'Original description', 'payload' => ['original' => true]]);
        $translationId = $action->translations()->where('locale', 'hr')->value('id');
        $otherTranslation = $action->translations()->where('locale', 'en')->firstOrFail()->getRawOriginal();
        $targets = $action->targets()->firstOrFail()->getRawOriginal();
        $sourcePayload = $action->payload;
        $createdBy = $action->created_by;
        $productBefore = $product->fresh()->getRawOriginal();

        $component = $this->manager($product)->call('edit', $action->id)
            ->assertSet('form.starts_at', '2026-01-02T03:04:05')
            ->set('form.title', 'Nova ponuda')->set('form.type', CatalogAction::TYPE_FIXED)
            ->set('form.discount_value', '17.50')->set('form.priority', 321)
            ->set('form.is_exclusive', false)->set('form.is_active', true)
            ->call('save')->assertHasNoErrors()->assertSet('editing', false)->assertSet('editingId', null)
            ->assertSee('82,50 €');
        $saved = $action->fresh();
        $this->assertSame($sourcePayload, $saved->payload);
        $this->assertSame(7, $saved->used_count);
        $this->assertSame($createdBy, $saved->created_by);
        $this->assertSame(321, $saved->priority);
        $this->assertFalse($saved->is_exclusive);
        $this->assertSame(CatalogAction::TYPE_FIXED, $saved->type);
        $this->assertSame(17.5, (float) $saved->discount_value);
        $this->assertSame($targets, $saved->targets()->firstOrFail()->getRawOriginal());
        $translation = $saved->translations()->where('locale', 'hr')->firstOrFail();
        $this->assertSame($translationId, $translation->id);
        $this->assertSame('Nova ponuda', $translation->title);
        $this->assertSame('Zadržani opis', $translation->description);
        $this->assertSame('Akcija', $translation->badge);
        $this->assertSame(['source' => 'retain'], $translation->payload);
        $this->assertSame($otherTranslation, $saved->translations()->where('locale', 'en')->firstOrFail()->getRawOriginal());
        $this->assertSame($productBefore, $product->fresh()->getRawOriginal());
        $this->assertDatabaseCount('catalog_actions', 1);
        $this->assertDatabaseCount('catalog_action_targets', 1);
        $component->assertSee('Nova ponuda');
    }

    public function test_create_action_is_public_direct_product_only_and_matches_existing_price_presenter(): void
    {
        $product = $this->product();
        $other = $this->product(['code' => 'other', 'sku' => 'OTHER']);
        $this->manager($product)->call('create')->set('form.title', 'Nova postotna ponuda')
            ->set('form.discount_value', 15)->call('save')->assertHasNoErrors()->assertSee('85,00 €');

        $action = CatalogAction::query()->sole();
        $this->assertSame(CatalogAction::SCOPE_PRODUCT, $action->scope);
        $this->assertSame(CatalogAction::TARGET_PRODUCT, $action->target_type);
        $this->assertSame(CatalogAction::AUDIENCE_ALL, $action->audience_type);
        $this->assertNull($action->coupon_code);
        $this->assertNull($action->usage_limit);
        $this->assertTrue($action->is_active);
        $this->assertSame($product->id, $action->targets()->sole()->target_id);
        $this->assertSame(85.0, app(ProductPricePresentationService::class)->forProduct($product, null)['current_gross']);
        $this->assertSame(100.0, app(ProductPricePresentationService::class)->forProduct($other, null)['current_gross']);
        $this->assertSame(100.0, (float) $product->fresh()->base_price);
    }

    #[DataProvider('invalidEdits')]
    public function test_invalid_values_do_not_modify_existing_action(array $changes, string $error): void
    {
        $product = $this->product();
        $action = $this->action($product);
        $before = $action->fresh()->getRawOriginal();
        $component = $this->manager($product)->call('edit', $action->id);
        foreach ($changes as $key => $value) {
            $component->set('form.'.$key, $value);
        }
        $component->call('save')->assertHasErrors([$error]);
        $this->assertSame($before, $action->fresh()->getRawOriginal());
    }

    public static function invalidEdits(): array
    {
        return [
            'percent above100' => [['discount_value' => 101], 'form.discount_value'],
            'zero discount' => [['discount_value' => 0], 'form.discount_value'],
            'negative discount' => [['discount_value' => -1], 'form.discount_value'],
            'unsupported complex type' => [['type' => CatalogAction::TYPE_BUY_X_GET_Y], 'form.type'],
            'inverted dates' => [['starts_at' => '2027-01-02T10:00', 'ends_at' => '2027-01-01T10:00'], 'form.ends_at'],
            'invalid date' => [['starts_at' => 'tomorrow nonsense'], 'form.starts_at'],
            'negative priority' => [['priority' => -1], 'form.priority'],
            'empty title' => [['title' => ''], 'form.title'],
        ];
    }

    #[DataProvider('complexActions')]
    public function test_shared_or_complex_actions_cannot_be_edited_inline(array $changes, bool $addSecondTarget = false): void
    {
        $product = $this->product();
        $action = $this->action($product, $changes);
        if ($addSecondTarget) {
            $action->targets()->create(['target_type' => CatalogAction::TARGET_PRODUCT, 'target_id' => $this->product(['code' => 'other', 'sku' => 'OTHER'])->id]);
        }
        $before = $action->fresh()->getRawOriginal();
        $this->manager($product)->assertSeeHtml(route('admin.actions.edit', ['action' => $action->id, 'locale' => 'hr']))
            ->call('edit', $action->id)->assertForbidden();
        $this->assertSame($before, $action->fresh()->getRawOriginal());
    }

    public static function complexActions(): array
    {
        return [
            'shared products' => [[], true],
            'coupon' => [['coupon_code' => 'SECRET10']],
            'user audience' => [['audience_type' => CatalogAction::AUDIENCE_USER]],
            'quantity promotion' => [['type' => CatalogAction::TYPE_BUY_X_GET_Y, 'buy_qty' => 2, 'get_qty' => 1]],
            'subtotal condition' => [['min_subtotal' => 70]],
            'usage limit' => [['usage_limit' => 1]],
            'payload rule' => [['payload' => ['conditions' => ['minimum_qty' => 3]]]],
        ];
    }

    public function test_save_rechecks_target_scope_after_action_changes_in_another_editor(): void
    {
        $product = $this->product();
        $action = $this->action($product);
        $component = $this->manager($product)->call('edit', $action->id)->set('form.discount_value', 40);
        $action->targets()->create(['target_type' => CatalogAction::TARGET_PRODUCT, 'target_id' => $this->product(['code' => 'other', 'sku' => 'OTHER'])->id]);
        $component->call('save')->assertForbidden();
        $this->assertSame(10.0, (float) $action->fresh()->discount_value);
        $this->assertSame(2, $action->targets()->count());
    }

    public function test_unrelated_action_id_is_rejected_and_ids_are_locked(): void
    {
        $product = $this->product();
        $other = $this->product(['code' => 'other', 'sku' => 'OTHER']);
        $action = $this->action($other);
        $this->manager($product)->call('edit', $action->id)->assertNotFound();
        $component = $this->manager($product);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('editingId', $action->id);
    }

    public function test_product_id_is_locked_against_cross_product_updates(): void
    {
        $product = $this->product();
        $component = $this->manager($product);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('productId', 999999);
    }

    public function test_action_permissions_are_enforced_even_inside_product_editor_and_after_permission_revocation(): void
    {
        $product = $this->product();
        $action = $this->action($product);
        $user = User::factory()->create();
        Bouncer::allow($user)->to(['admin.access', 'catalog.products.update', 'catalog.actions.view']);
        Livewire::actingAs($user)->test(PromotionManager::class, ['productId' => $product->id])
            ->call('edit', $action->id)->assertForbidden();
        Livewire::actingAs($user)->test(PromotionManager::class, ['productId' => $product->id])
            ->call('create')->assertForbidden();
        Bouncer::allow($user)->to('catalog.actions.update');
        Bouncer::refreshFor($user);
        $component = Livewire::actingAs($user)->test(PromotionManager::class, ['productId' => $product->id])->call('edit', $action->id);
        Bouncer::disallow($user)->to('catalog.actions.update');
        Bouncer::refreshFor($user);
        $component->set('form.discount_value', 90)->call('save')->assertForbidden();
        $this->assertSame(10.0, (float) $action->fresh()->discount_value);
    }

    private function manager(Product $product)
    {
        return Livewire::actingAs($this->admin())->test(PromotionManager::class, ['productId' => $product->id, 'locale' => 'hr']);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        Bouncer::assign('admin')->to($admin);

        return $admin;
    }

    private function product(array $changes = []): Product
    {
        $product = Product::query()->create(array_replace([
            'code' => 'watch', 'sku' => 'WATCH', 'base_price' => 100, 'stock_qty' => 5, 'is_active' => true,
        ], $changes));
        $product->translations()->create(['locale' => 'hr', 'name' => 'Sat '.$product->code, 'slug' => 'sat-'.$product->code]);

        return $product;
    }

    private function action(?Product $product = null, array $changes = [], string $title = 'Početna akcija'): CatalogAction
    {
        $action = CatalogAction::query()->create(array_replace([
            'code' => 'test-action-'.CatalogAction::query()->count(),
            'scope' => CatalogAction::SCOPE_PRODUCT, 'type' => CatalogAction::TYPE_PERCENTAGE,
            'discount_value' => 10, 'target_type' => CatalogAction::TARGET_PRODUCT,
            'audience_type' => CatalogAction::AUDIENCE_ALL, 'is_active' => true,
            'is_exclusive' => false, 'priority' => 100, 'created_by' => auth()->id(),
        ], $changes));
        $action->translations()->create(['locale' => 'hr', 'title' => $title]);
        if ($product) {
            $action->targets()->create(['target_type' => CatalogAction::TARGET_PRODUCT, 'target_id' => $product->id, 'sort_order' => 13]);
        }

        return $action;
    }
}
