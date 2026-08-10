<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use App\Models\SeoDatabaseConnection;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class SeoAccessControlAdminViewerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.core_connection', 'sqlite');
        Config::set('database.connections.omi_seo_ai', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    public function test_admin_viewer_resolves_panel_owner_from_connection(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner-admin-view@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
            'seo_role' => User::SEO_ROLE_MANAGER,
        ]);

        $staff = User::query()->create([
            'name' => 'Staff',
            'email' => 'staff-admin-view@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_STAFF,
            'parent_id' => $owner->id,
            'status' => User::STATUS_NORMAL,
            'seo_role' => User::SEO_ROLE_PLANNER,
        ]);

        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-admin-view@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_NORMAL,
        ]);

        Site::query()->create([
            'user_id' => $owner->id,
            'domain' => 'owner-panel.test',
            'status' => 'active',
        ]);

        Site::query()->create([
            'user_id' => $admin->id,
            'domain' => 'admin-panel.test',
            'status' => 'active',
        ]);

        $connection = SeoDatabaseConnection::query()->create([
            'name' => 'Owner workspace',
            'hash_id' => str_repeat('a', 32),
            'type' => 'manual',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'omi_seo_ai_owner',
            'username' => 'root',
            'password' => 'secret',
            'is_active' => true,
        ]);
        $connection->users()->attach($owner->id);

        $this->actingAs($admin);
        SeoConnectionContext::set($connection);

        $this->assertTrue(SeoAccessControl::isSeoPanelAdminViewer());
        $this->assertTrue(SeoAccessControl::isSeoPanelReadOnly());
        $this->assertFalse(SeoAccessControl::canMutateInSeoPanel());
        $this->assertSame($owner->id, SeoAccessControl::panelOwnerId());
        $this->assertSame($owner->id, SeoAccessControl::accountOwnerId());
        $this->assertSame([$staff->id], User::query()
            ->where('parent_id', SeoAccessControl::accountOwnerId())
            ->where('role', User::ROLE_STAFF)
            ->pluck('id')
            ->all());
        $this->assertSame(
            ['owner-panel.test'],
            SeoAccessControl::accessibleSitesQuery()->orderBy('domain')->pluck('domain')->all(),
        );
        $this->assertFalse(SeoAccessControl::canMutateContentProjects());
        $this->assertFalse(SeoAccessControl::canDeleteSeoMedia());
    }
}
