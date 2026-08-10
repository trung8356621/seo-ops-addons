<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class SeoAccessControlSiteScopeTest extends TestCase
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

    public function test_apply_accessible_site_scope_uses_resolved_ids_not_subquery(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner-scope@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
            'seo_role' => User::SEO_ROLE_MANAGER,
        ]);

        $site = Site::query()->create([
            'user_id' => $owner->id,
            'domain' => 'example.test',
            'status' => 'active',
        ]);

        $this->actingAs($owner);

        $query = SeoAccessControl::applyAccessibleSiteScope(SeoArticle::query());
        $sql = $query->toSql();

        $this->assertStringNotContainsString('sites', strtolower($sql));
        $this->assertSame([$site->id], SeoAccessControl::accessibleSiteIds());
    }
}
