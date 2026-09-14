<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use App\Core\Permissions\AddonPermissionRegistry;
use App\Core\Permissions\SeoRoleAssignment;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Tests\TestCase;

final class SeoAccessControlMediaPermissionsTest extends TestCase
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

    public function test_content_manager_can_access_content_features(): void
    {
        $this->actingAs($this->staffWithRole(User::SEO_ROLE_CONTENT_MANAGER));

        $this->assertTrue(SeoAccessControl::canAccessContentFeatures());
    }

    public function test_content_manager_cannot_delete_seo_media(): void
    {
        $this->actingAs($this->staffWithRole(User::SEO_ROLE_CONTENT_MANAGER));

        $this->assertFalse(SeoAccessControl::canDeleteSeoMedia());
    }

    public function test_planner_can_delete_seo_media(): void
    {
        $this->actingAs($this->staffWithRole(User::SEO_ROLE_PLANNER));

        $this->assertTrue(SeoAccessControl::canDeleteSeoMedia());
    }

    private function staffWithRole(string $short): User
    {
        static $n = 0;
        $n++;
        $user = User::query()->create([
            'name' => "Media {$n}",
            'email' => "media{$n}@example.com",
            'password' => Hash::make('password'),
            'role' => User::ROLE_STAFF,
            'parent_id' => 10,
            'status' => User::STATUS_NORMAL,
        ]);
        app(SeoRoleAssignment::class)->assign($user, $short);

        return $user->fresh();
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

    protected function tearDown(): void
    {
        Auth::logout();

        parent::tearDown();
    }
}
