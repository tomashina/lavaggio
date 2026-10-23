<?php

namespace Tests\Feature\Import;

use App\Models\User;
use App\Models\User\LegacyCredential;
use App\Services\Import\WatchlineStaffImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class WatchlineStaffImportTest extends TestCase
{
    use RefreshDatabase;

    private string $sourceFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sourceFile = tempnam(sys_get_temp_dir(), 'watchline-staff-test-');
        config(['database.connections.watchline_staff_test' => ['driver' => 'sqlite', 'database' => $this->sourceFile, 'prefix' => '']]);
        DB::purge('watchline_staff_test');
        $schema = DB::connection('watchline_staff_test')->getSchemaBuilder();
        $schema->create('oc_user_group', function (Blueprint $table): void {
            $table->integer('user_group_id');
            $table->string('name');
            $table->text('permission');
        });
        $schema->create('oc_user', function (Blueprint $table): void {
            $table->integer('user_id');
            $table->integer('user_group_id');
            foreach (['username', 'email', 'firstname', 'lastname', 'password', 'salt'] as $column) {
                $table->string($column);
            }
            $table->integer('status');
        });
        $source = DB::connection('watchline_staff_test');
        $source->table('oc_user_group')->insert(['user_group_id' => 1, 'name' => 'Administrator',
            'permission' => json_encode(['access' => ['user/user', 'user/user_permission'], 'modify' => ['user/user', 'user/user_permission']])]);
        $salt = 'test-salt';
        $source->table('oc_user')->insert(['user_id' => 1, 'user_group_id' => 1, 'username' => 'WatchlineAdmin',
            'email' => 'shared@example.test', 'firstname' => 'Test', 'lastname' => 'Admin',
            'password' => sha1($salt.sha1($salt.sha1('original-admin-password'))), 'salt' => $salt, 'status' => 1]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('watchline_staff_test');
        unlink($this->sourceFile);
        parent::tearDown();
    }

    public function test_dry_run_needs_no_herrera_assignment_extension_and_does_not_write(): void
    {
        $sourceBefore = DB::connection('watchline_staff_test')->table('oc_user')->get()->toJson();
        $report = $this->import(false);
        $this->assertSame(1, $report['staff']);
        $this->assertSame(0, $report['assignments']);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('user_legacy_credentials', 0);
        $this->assertSame($sourceBefore, DB::connection('watchline_staff_test')->table('oc_user')->get()->toJson());
    }

    public function test_original_sha1_password_and_username_log_in_through_the_actual_admin_form(): void
    {
        $customer = User::factory()->create(['email' => 'shared@example.test']);
        $sourceBefore = DB::connection('watchline_staff_test')->table('oc_user')->get()->toJson();
        $this->import();
        $admin = User::where('account_type', 'staff')->sole();
        $credential = LegacyCredential::where('user_id', $admin->id)->sole();
        $this->assertNotSame($customer->id, $admin->id);
        $this->assertTrue($admin->isA('admin'));
        $this->assertTrue($admin->can('admin.access'));
        $this->assertTrue($admin->can('users.access.manage'));
        $this->assertNotSame($credential->legacy_hash, $credential->getRawOriginal('legacy_hash'));

        $this->get('/admin/login')->assertRedirect('/login');
        Volt::test('pages.auth.login')
            ->set('form.email', '  WATCHLINEADMIN  ')
            ->set('form.password', 'original-admin-password')
            ->call('login')->assertHasNoErrors()->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($admin);
        $this->get('/admin/dashboard')->assertOk();
        $this->assertTrue(Hash::check('original-admin-password', $admin->fresh()->password));
        $this->assertFalse($credential->fresh()->enabled);
        $this->assertNotNull($credential->fresh()->retired_at);
        $this->assertSame($sourceBefore, DB::connection('watchline_staff_test')->table('oc_user')->get()->toJson());
    }

    public function test_staff_email_uses_original_password_and_disabled_source_accounts_cannot_login(): void
    {
        $this->import();
        Volt::test('pages.auth.login')->set('form.email', 'SHARED@example.test')
            ->set('form.password', 'original-admin-password')->call('login')->assertHasNoErrors();
        $this->assertAuthenticated();
        Auth::logout();
        DB::connection('watchline_staff_test')->table('oc_user')->update(['status' => 0]);
        $this->import();
        Volt::test('pages.auth.login')->set('form.email', 'WatchlineAdmin')
            ->set('form.password', 'original-admin-password')->call('login')->assertHasErrors('form.email');
        $this->assertGuest();
    }

    public function test_reimport_preserves_native_fallback_and_changed_password_without_reactivating_old_password(): void
    {
        $fallback = User::factory()->create(['email' => 'admin@watchline.test', 'account_type' => 'staff']);
        Bouncer::assign('superadmin')->to($fallback);
        $nativeHash = $fallback->password;
        $this->import();
        $admin = User::where('admin_username', 'WatchlineAdmin')->sole();
        $admin->update(['password' => Hash::make('new-local-password')]);
        $this->import();
        $this->assertDatabaseCount('users', 2);
        $this->assertSame($nativeHash, $fallback->fresh()->password);
        $this->assertTrue($fallback->fresh()->isA('superadmin'));
        $this->assertFalse(LegacyCredential::where('user_id', $admin->id)->sole()->enabled);
        $this->assertTrue(Hash::check('new-local-password', $admin->fresh()->password));
    }

    public function test_command_outputs_only_aggregate_counts_and_can_be_repeated(): void
    {
        $arguments = ['--source-connection' => 'watchline_staff_test'];
        $this->assertSame(0, Artisan::call('watchline:import-staff', $arguments));
        $output = Artisan::output();
        $this->assertStringNotContainsString('WatchlineAdmin', $output);
        $this->assertStringNotContainsString('shared@example.test', $output);
        $this->assertStringNotContainsString('test-salt', $output);
        $this->assertSame(0, Artisan::call('watchline:import-staff', $arguments));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_legacy_credentials', 1);
    }

    public function test_nonlocal_context_is_rejected_before_any_account_is_created(): void
    {
        app()->instance('env', 'production');
        try {
            $this->import();
            $this->fail('Production must not accept the local Watchline importer.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('isolated local', $exception->getMessage());
            $this->assertDatabaseCount('users', 0);
        } finally {
            app()->instance('env', 'testing');
        }
    }

    public function test_case_insensitive_existing_username_collision_is_rejected_before_import(): void
    {
        $existing = User::factory()->create(['account_type' => 'staff', 'email' => 'other@example.test']);
        $existing->forceFill(['admin_username' => 'watchlineadmin'])->save();
        $before = $existing->fresh()->getRawOriginal();
        try {
            $this->import();
            $this->fail('A username with a different email must not create an ambiguous staff login.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('different email', $exception->getMessage());
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_legacy_credentials', 0);
        $this->assertSame($before, $existing->fresh()->getRawOriginal());
    }

    private function import(bool $apply = true): array
    {
        return app(WatchlineStaffImportService::class)->import(DB::connection('watchline_staff_test'), 'oc_', $apply);
    }
}
