<?php

namespace Tests\Feature\Front;

use App\Livewire\Admin\Catalog\Action\Form;
use App\Livewire\Admin\Catalog\Action\Manager;
use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Services\Front\GuestStorefrontCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class LavaggioPromotionsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'lavaggio', 'app.locale' => 'hr', 'app.timezone' => 'UTC',
            'commerce.b2c_only' => true, 'storefront_cache.store' => 'array']);
        app()->setLocale('hr');
    }

    public static function dates(): array
    {
        return [['2026-11-24', '11'], ['2026-07-24', '10']];
    }

    #[DataProvider('dates')]
    public function test_noon_promotion_round_trips_and_only_discounts_appear_inside_its_window(string $day, string $utcHour): void
    {
        $product = $this->product('akcijski-artikl');
        $fullPrice = $this->product('redovna-cijena');
        $admin = User::factory()->create();
        Bouncer::assign('admin')->to($admin);
        Livewire::actingAs($admin)->test(Form::class)->set('form.code', 'podne')->set('form.title', 'Akcija u podne')
            ->set('form.discount_value', 15)->set('form.target_type', CatalogAction::TARGET_PRODUCT)
            ->set('form.target_ids', [$product->id])->set('form.starts_at', $day.'T12:00')->set('form.ends_at', $day.'T12:30')
            ->call('save')->assertHasNoErrors();
        $action = CatalogAction::where('code', 'podne')->sole();
        $this->assertSame("$day $utcHour:00:00", $action->getRawOriginal('starts_at'));
        Livewire::test(Form::class, ['actionId' => $action->id])->assertSet('form.starts_at', $day.'T12:00:00')
            ->assertSet('form.ends_at', $day.'T12:30:00')->call('save')->assertHasNoErrors();
        Livewire::test(Manager::class)->assertSee($day.' 12:00')->assertSee($day.' 12:30');

        $coupon = $action->replicate();
        $coupon->fill(['code' => 'kupon', 'target_type' => CatalogAction::TARGET_ALL, 'coupon_code' => 'POSEBAN'])->save();
        auth()->logout();
        $cache = app(GuestStorefrontCache::class);
        $previousKey = null;
        foreach (['11:59:59' => false, '12:00:00' => true, '12:30:00' => true, '12:30:01' => false] as $time => $active) {
            $this->travelTo(Carbon::parse("$day $time", 'Europe/Zagreb')->utc());
            $response = $this->get('/akcije?promo_only=0')->assertOk()->assertSee('Artikli na akciji')
                ->assertViewHas('products', fn ($page) => $page->getCollection()->modelKeys() === ($active ? [$product->id] : []))
                ->assertDontSee($fullPrice->code);
            $key = $cache->key(request(), [], 'default');
            if ($time === '12:00:00' || $time === '12:30:01') {
                $this->assertNotSame($previousKey, $key);
            }
            $previousKey = $key;
            if (! $active) {
                $response->assertSee('Trenutno nema artikala na akciji.');
            }
        }
    }

    private function product(string $code): Product
    {
        $product = Product::create(['code' => $code, 'base_price' => 10, 'stock_qty' => 2, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => $code, 'slug' => $code]);

        return $product;
    }
}
