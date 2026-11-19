<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use App\Core\Permissions\AddonPermissionRegistry;
use App\Core\Permissions\SeoRoleAssignment;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Tests\TestCase;

final class SeoAccessControlPanelAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.core_connection', 'sqlite');
        Config::set('permission.testing', true);
        $this->createPermissionSchema();

        $registry = app(AddonPermissionRegistry::class);
        $registry->register('seo', [
            SeoRoleAssignment::ROLE_MANAGER,
            SeoRoleAssignment::ROLE_PLANNER,
            SeoRoleAssignment::ROLE_CONTENT_MANAGER,
        ]);
        $registry->syncToDatabase();
    }

    public function test_owner_can_access_seo_panel(): void
    {
        $user = $this->makeUser(User::ROLE_OWNER);

        $this->assertTrue(SeoAccessControl::canAccessSeoPanel($user));
    }

    public function test_staff_team_member_with_seo_role_can_access_seo_panel(): void
    {
        $user = $this->makeUser(User::ROLE_STAFF, parentId: 10);
        app(SeoRoleAssignment::class)->assign($user, User::SEO_ROLE_CONTENT_MANAGER);

        $this->assertTrue(SeoAccessControl::canAccessSeoPanel($user->fresh()));
    }

    public function test_staff_without_seo_role_cannot_access_seo_panel(): void
    {
        $user = $this->makeUser(User::ROLE_STAFF, parentId: 10);

        $this->assertFalse(SeoAccessControl::canAccessSeoPanel($user));
    }

    public function test_staff_without_owner_link_cannot_access_seo_panel(): void
    {
        $user = $this->makeUser(User::ROLE_STAFF, parentId: null);
        app(SeoRoleAssignment::class)->assign($user, User::SEO_ROLE_CONTENT_MANAGER);

        $this->assertFalse(SeoAccessControl::canAccessSeoPanel($user->fresh()));
    }

    public function test_blocked_user_cannot_access_seo_panel(): void
    {
        $user = $this->makeUser(User::ROLE_OWNER, status: User::STATUS_BLOCK);

        $this->assertFalse(SeoAccessControl::canAccessSeoPanel($user));
    }

    public function test_owner_simulating_content_manager_keeps_global_bar_and_can_switch_back(): void
    {
        $owner = $this->makeUser(User::ROLE_OWNER);
        // Even with stale seo.content_manager Spatie role, Owner actual role remains manager.
        app(SeoRoleAssignment::class)->assign($owner, User::SEO_ROLE_CONTENT_MANAGER);
        $this->be($owner->fresh());

        $this->assertSame(SeoAccessControl::ROLE_MANAGER, SeoAccessControl::actualRole());
        session(['seo_simulated_role' => SeoAccessControl::ROLE_CONTENT_MANAGER]);

        $this->assertSame(SeoAccessControl::ROLE_CONTENT_MANAGER, SeoAccessControl::effectiveRole());
        $this->assertTrue(SeoAccessControl::shouldShowGlobalSeoBar());
        $this->assertContains(SeoAccessControl::ROLE_MANAGER, SeoAccessControl::allowedSimulationTargets());

        session(['seo_simulated_role' => SeoAccessControl::ROLE_MANAGER]);
        $this->assertSame(SeoAccessControl::ROLE_MANAGER, SeoAccessControl::effectiveRole());
        $this->assertTrue(SeoAccessControl::canAccessManagerFeatures());
    }

    public function test_real_content_manager_cannot_access_manager_features_or_global_bar(): void
    {
        $staff = $this->makeUser(User::ROLE_STAFF, parentId: 1);
        app(SeoRoleAssignment::class)->assign($staff, User::SEO_ROLE_CONTENT_MANAGER);
        $this->be($staff->fresh());

        $this->assertSame(SeoAccessControl::ROLE_CONTENT_MANAGER, SeoAccessControl::actualRole());
        $this->assertFalse(SeoAccessControl::shouldShowGlobalSeoBar());
        $this->assertFalse(SeoAccessControl::canAccessManagerFeatures());
        $this->assertSame([SeoAccessControl::ROLE_CONTENT_MANAGER], SeoAccessControl::allowedSimulationTargets());
    }

    private function makeUser(
        string $role,
        ?int $parentId = null,
        string $status = User::STATUS_NORMAL,
    ): User {
        static $n = 0;
        $n++;

        return User::query()->create([
            'name' => "SeoPanel {$n}",
            'email' => "seopanel{$n}@example.com",
            'password' => Hash::make('password'),
            'role' => $role,
            'status' => $status,
            'parent_id' => $parentId,
        ]);
    }

    private function createPermissionSchema(): void
    {
        $connection = (string) config('database.core_connection', 'sqlite');

        foreach ([
            'role_has_permissions',
            'model_has_roles',
            'model_has_permissions',
            'roles',
            'permissions',
            'users',
        ] as $table) {
            Schema::connection($connection)->dropIfExists($table);
        }

        Schema::connection($connection)->create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('staff');
            $table->string('status')->default('normal');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_09_14_021503_create_permission_tables.php',
            '--force' => true,
        ]);
    }
}
