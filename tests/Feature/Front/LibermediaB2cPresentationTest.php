<?php

namespace Tests\Feature\Front;

use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LibermediaB2cPresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['storefront.theme' => 'herrera', 'storefront.brand' => 'libermedia', 'storefront.home' => 'libermedia', 'commerce.b2b_only' => false]);
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Liber Media',
            'store_pricing_b2c_enabled' => true,
        ]);
    }

    public function test_public_pages_use_light_appearance_and_only_customer_account_links(): void
    {
        foreach (['/shop', '/auth/login', '/auth/register', '/auth/forgot-password'] as $path) {
            $this->withHeader('User-Agent', 'iPhone Mobile')->get($path)->assertOk()
                ->assertSee('data-theme="light"', false)
                ->assertDontSee('data-storefront-theme-toggle', false)
                ->assertDontSee('data-storefront-theme-boot', false)
                ->assertDontSee('scripts/storefront-theme.js', false)
                ->assertDontSee(route('front.auth.b2b-register'), false)
                ->assertDontSee(route('account.b2b.quick-order'), false)
                ->assertDontSee('B2B prijava')
                ->assertDontSee('Registracija tvrtke')
                ->assertSee(route('front.auth.login'), false);
        }

        $this->get(route('front.auth.login'))->assertOk()->assertSee(route('front.auth.register'), false);
    }

    public function test_ordinary_customer_can_still_register_and_sign_in(): void
    {
        $this->post(route('front.auth.register.store'), [
            'first_name' => 'Mali',
            'last_name' => 'Kupac',
            'email' => 'kupac@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms_accepted' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect(route('account.dashboard'));

        $customer = User::query()->where('email', 'kupac@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($customer);
        $this->assertNull($customer->b2bAccount);
        $this->post(route('logout'))->assertRedirect();
        $this->assertGuest();

        $this->post(route('front.auth.login.store'), [
            'email' => $customer->email,
            'password' => 'password',
        ])->assertSessionHasNoErrors()->assertRedirect(route('account.dashboard'));
        $this->assertAuthenticatedAs($customer);
    }

    public function test_old_business_registration_urls_lead_to_customer_registration(): void
    {
        $parameters = ['intended' => '/cart'];
        $this->get(route('front.auth.b2b-register', $parameters))
            ->assertRedirect(route('front.auth.register', $parameters));
        $this->post(route('front.auth.b2b-register.store'), $parameters)
            ->assertRedirect(route('front.auth.register', $parameters));
        $this->assertDatabaseCount('b2b_accounts', 0);
    }

    public function test_inherited_business_account_does_not_expose_business_navigation(): void
    {
        $customer = User::factory()->create();
        $group = CustomerGroup::query()->create(['code' => 'old-business', 'name' => 'Old business', 'is_active' => true]);
        $customer->customerGroups()->attach($group);
        B2BAccount::query()->create([
            'user_id' => $customer->id,
            'status' => B2BAccount::STATUS_APPROVED,
            'company_name' => 'Old company',
            'oib' => '12345678901',
            'customer_group_id' => $group->id,
        ]);

        $this->actingAs($customer)->get(route('account.dashboard'))->assertOk()
            ->assertDontSee('data-storefront-theme-toggle', false)
            ->assertDontSee('data-account-status', false)
            ->assertDontSee('data-account-order-shortcuts', false)
            ->assertDontSee('data-b2b-approval-notice', false)
            ->assertDontSee(route('account.b2b.quick-order'), false)
            ->assertSee(route('account.orders'), false)
            ->assertSee(route('account.profile'), false);
    }
}
