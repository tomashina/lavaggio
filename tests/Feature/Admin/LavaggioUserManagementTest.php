<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\User\Form as UserForm;
use App\Livewire\Admin\User\Manager as UserManager;
use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Models\User\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class LavaggioUserManagementTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Strong-Test-Password-42!';

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'lavaggio', 'app.locale' => 'hr', 'app.fallback_locale' => 'hr']);
        Mail::fake();
        Notification::fake();
    }

    #[DataProvider('creatableRoles')]
    public function test_admin_can_create_staff_or_customer_with_the_selected_role_and_hashed_password(string $role, string $accountType): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin)->get(route('admin.users.create'))->assertOk();
        $this->createForm($admin, $role)->call('save')->assertHasNoErrors()->assertRedirect(route('admin.users'));

        $created = User::where('email', 'new-'.$role.'@example.test')->sole();
        $this->assertSame('Novi korisnik '.$role, $created->name);
        $this->assertSame($accountType, $created->account_type);
        $this->assertSame([$role], $created->roles()->pluck('name')->all());
        $this->assertTrue(Hash::check(self::PASSWORD, $created->password));
        $this->assertNotSame(self::PASSWORD, $created->password);
        $this->assertNotNull($created->email_verified_at);
        if ($accountType === 'staff') {
            $this->assertTrue($created->admin_login_enabled);
            $this->assertTrue($created->can('admin.access'));
        } else {
            $this->assertFalse($created->can('admin.access'));
        }
        $activity = Activity::where('subject_type', User::class)->where('subject_id', $created->id)->first();
        $this->assertNotNull($activity);
        $this->assertStringNotContainsString(self::PASSWORD, $activity->properties->toJson());
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public static function creatableRoles(): array
    {
        return [
            'administrator' => ['admin', 'staff'],
            'editor' => ['editor', 'staff'],
            'order manager' => ['order_manager', 'staff'],
            'customer' => ['customer', 'customer'],
        ];
    }

    public function test_created_administrator_can_sign_in_and_open_the_admin_dashboard(): void
    {
        $admin = $this->user('admin');
        $this->createForm($admin, 'admin')->call('save')->assertHasNoErrors();
        $created = User::where('email', 'new-admin@example.test')->sole();
        Auth::logout();
        Auth::forgetGuards();
        $this->assertGuest();

        Volt::test('pages.auth.login')
            ->set('form.email', $created->email)->set('form.password', self::PASSWORD)
            ->call('login')->assertHasNoErrors()->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($created);
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_customer_creation_saves_optional_profile_and_groups_without_requiring_addresses(): void
    {
        $admin = $this->user('admin');
        $group = CustomerGroup::create(['code' => 'new-customer-group', 'name' => 'Kupci', 'is_active' => true]);
        $this->createForm($admin, 'customer')
            ->set('form.profile.first_name', 'Ana')->set('form.profile.last_name', 'Horvat')
            ->set('form.profile.phone', '+385911234567')->set('form.customer_groups', [$group->id])
            ->set('form.email_verified', false)->call('save')->assertHasNoErrors();

        $created = User::where('email', 'new-customer@example.test')->sole();
        $this->assertSame('Ana', $created->profile->first_name);
        $this->assertSame('Horvat', $created->profile->last_name);
        $this->assertSame('+385911234567', $created->profile->phone);
        $this->assertSame([$group->id], $created->customerGroups()->pluck('customer_groups.id')->all());
        $this->assertNull($created->email_verified_at);
    }

    #[DataProvider('optionalShippingAddresses')]
    public function test_creation_does_not_treat_inherited_billing_company_as_entered_shipping_data(bool $withShipping): void
    {
        $admin = $this->user('admin');
        $component = $this->createForm($admin, 'customer')
            ->set('form.billing_address.company', 'Primjer d.o.o.')
            ->set('form.billing_address.first_name', 'Ana')
            ->set('form.billing_address.phone', '+385911234567');
        if ($withShipping) {
            $component->set('form.shipping_address.address_line_1', 'Primorska 5')
                ->set('form.shipping_address.city', 'Split')
                ->set('form.shipping_address.postal_code', '21000');
        }
        $component->call('save')->assertHasNoErrors();

        $created = User::where('email', 'new-customer@example.test')->sole();
        $billing = $created->addresses()->where('type', UserAddress::TYPE_BILLING)->sole();
        $this->assertSame('Primjer d.o.o.', $billing->company);
        $this->assertSame('Ana', $billing->first_name);
        $this->assertSame('+385911234567', $billing->phone);
        $shipping = $created->addresses()->where('type', UserAddress::TYPE_SHIPPING);
        if ($withShipping) {
            $shippingAddress = $shipping->sole();
            $this->assertSame('Primorska 5', $shippingAddress->address_line_1);
            $this->assertSame('Split', $shippingAddress->city);
            $this->assertSame('21000', $shippingAddress->postal_code);
            $this->assertSame('Primjer d.o.o.', $shippingAddress->company);
        } else {
            $this->assertFalse($shipping->exists());
        }
        $this->assertSame($withShipping ? 2 : 1, $created->addresses()->count());
    }

    public static function optionalShippingAddresses(): array
    {
        return [
            'billing company and contact only' => [false],
            'explicit shipping address' => [true],
        ];
    }

    public function test_admin_cannot_forge_superadmin_role_but_superadmin_can_create_another_superadmin(): void
    {
        $admin = $this->user('admin');
        $count = User::count();
        $this->createForm($admin, 'superadmin')->call('save')->assertHasErrors('form.role');
        $this->assertSame($count, User::count());

        $superadmin = $this->user('superadmin');
        $this->createForm($superadmin, 'superadmin')->call('save')->assertHasNoErrors();
        $created = User::where('email', 'new-superadmin@example.test')->sole();
        $this->assertTrue($created->isA('superadmin'));
        $this->assertSame('staff', $created->account_type);
        $this->assertTrue($created->admin_login_enabled);
    }

    #[DataProvider('invalidCreation')]
    public function test_invalid_creation_does_not_save_any_partial_user(array $changes, string $error): void
    {
        $admin = $this->user('admin');
        User::factory()->create(['account_type' => 'customer', 'email' => 'taken@example.test']);
        $before = User::count();
        $component = $this->createForm($admin, 'customer');
        foreach ($changes as $key => $value) {
            $component->set('form.'.$key, $value);
        }
        $component->call('save')->assertHasErrors('form.'.$error);
        $this->assertSame($before, User::count());
        $this->assertDatabaseMissing('users', ['email' => 'new-customer@example.test']);
    }

    public static function invalidCreation(): array
    {
        return [
            'missing password' => [['password' => '', 'password_confirmation' => ''], 'password'],
            'weak password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
            'unconfirmed password' => [['password_confirmation' => 'does-not-match'], 'password'],
            'duplicate email in customer identities' => [['email' => 'taken@example.test'], 'email'],
            'unknown role' => [['role' => 'nonexistent-role'], 'role'],
        ];
    }

    public function test_customer_and_staff_identity_namespaces_can_share_an_email_without_duplicate_staff_accounts(): void
    {
        $admin = $this->user('admin');
        $customer = User::factory()->create(['account_type' => 'customer', 'email' => 'same-person@example.test']);
        $this->createForm($admin, 'admin')->set('form.email', $customer->email)
            ->call('save')->assertHasNoErrors();
        $this->assertSame(2, User::where('email', $customer->email)->count());

        $before = User::count();
        $this->createForm($admin, 'editor')->set('form.email', $customer->email)
            ->call('save')->assertHasErrors('form.email');
        $this->assertSame($before, User::count());
    }

    public function test_guests_cannot_open_or_mount_user_creation(): void
    {
        $this->get(route('admin.users.create'))->assertRedirect(route('login'));
        Livewire::test(UserForm::class)->assertForbidden();
    }

    #[DataProvider('deniedActors')]
    public function test_unauthorized_staff_cannot_open_or_mount_creation_and_do_not_see_the_create_button(string $role, bool $forbidden): void
    {
        $actor = $this->user($role);
        if ($forbidden) {
            Bouncer::forbid($actor)->to('users.profile.update');
            Bouncer::refreshFor($actor);
        }
        $this->actingAs($actor)->get(route('admin.users.create'))->assertForbidden();
        Livewire::actingAs($actor)->test(UserForm::class)->assertForbidden();
        if ($actor->can('users.list.view')) {
            Livewire::actingAs($actor)->test(UserManager::class)->assertDontSeeHtml(route('admin.users.create'));
        }
    }

    public static function deniedActors(): array
    {
        return [
            'editor' => ['editor', false],
            'restricted order manager' => ['order_manager', false],
            'explicitly forbidden administrator' => ['admin', true],
        ];
    }

    public function test_creation_permission_is_checked_again_when_the_open_form_is_saved(): void
    {
        $admin = $this->user('admin');
        $component = $this->createForm($admin, 'admin');
        $before = User::count();
        Bouncer::forbid($admin)->to('users.profile.update');
        Bouncer::refreshFor($admin);

        $component->call('save')->assertForbidden();
        $this->assertSame($before, User::count());
    }

    public function test_profile_permission_without_admin_access_does_not_allow_direct_creation_or_show_the_create_button(): void
    {
        $actor = $this->user('customer');
        Bouncer::allow($actor)->to(['users.profile.update', 'users.list.view']);
        Bouncer::refreshFor($actor);
        $this->assertTrue($actor->can('users.profile.update'));
        $this->assertFalse($actor->can('admin.access'));

        $this->actingAs($actor)->get(route('admin.users.create'))->assertForbidden();
        Livewire::actingAs($actor)->test(UserForm::class)->assertForbidden();
        Livewire::actingAs($actor)->test(UserManager::class)->assertDontSeeHtml(route('admin.users.create'));
    }

    public function test_revoked_admin_access_prevents_saving_an_open_creation_form_even_when_profile_permission_remains(): void
    {
        $admin = $this->user('admin');
        $component = $this->createForm($admin, 'admin');
        $before = User::count();
        Bouncer::forbid($admin)->to('admin.access');
        Bouncer::refreshFor($admin);
        $this->assertTrue($admin->can('users.profile.update'));
        $this->assertFalse($admin->can('admin.access'));

        $component->call('save')->assertForbidden();
        $this->assertSame($before, User::count());
    }

    public function test_lavaggio_user_list_ignores_retired_commercialist_filters_and_preserves_the_primary_role_filter(): void
    {
        $admin = $this->user('admin');
        $first = $this->user('customer');
        $second = $this->user('customer');
        Livewire::withQueryParams(['representative' => $admin->id, 'visit' => 'unvisited'])->actingAs($admin)
            ->test(UserManager::class)->set('role', 'customer')
            ->set('representative', (string) $admin->id)->set('county', 'Ne postoji')->set('visit', 'visited')
            ->assertSet('representative', '')->assertSet('county', '')->assertSet('visit', '')
            ->assertSet('role', 'customer')
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->sort()->values()->all() === [$first->id, $second->id])
            ->assertDontSeeHtml('wire:click="assignSelectedRepresentatives"')
            ->assertDontSeeHtml('wire:click="assignSelectedSegments"')
            ->assertDontSeeHtml('wire:model.live="selectedCustomers"');
    }

    private function createForm(User $actor, string $role)
    {
        return Livewire::actingAs($actor)->test(UserForm::class)
            ->set('form.name', 'Novi korisnik '.$role)->set('form.email', 'new-'.$role.'@example.test')
            ->set('form.role', $role)->set('form.email_verified', true)
            ->set('form.password', self::PASSWORD)->set('form.password_confirmation', self::PASSWORD);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['account_type' => $role === 'customer' ? 'customer' : 'staff']);
        Bouncer::assign($role)->to($user);

        return $user;
    }
}
