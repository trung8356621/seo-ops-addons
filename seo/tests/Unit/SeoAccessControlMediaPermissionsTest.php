<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

final class SeoAccessControlMediaPermissionsTest extends TestCase
{
    public function test_content_manager_can_access_content_features(): void
    {
        $this->actingAs(new User([
            'role' => User::ROLE_STAFF,
            'parent_id' => 10,
            'seo_role' => User::SEO_ROLE_CONTENT_MANAGER,
            'status' => User::STATUS_NORMAL,
        ]));

        $this->assertTrue(SeoAccessControl::canAccessContentFeatures());
    }

    public function test_content_manager_cannot_delete_seo_media(): void
    {
        $this->actingAs(new User([
            'role' => User::ROLE_STAFF,
            'parent_id' => 10,
            'seo_role' => User::SEO_ROLE_CONTENT_MANAGER,
            'status' => User::STATUS_NORMAL,
        ]));

        $this->assertFalse(SeoAccessControl::canDeleteSeoMedia());
    }

    public function test_planner_can_delete_seo_media(): void
    {
        $this->actingAs(new User([
            'role' => User::ROLE_STAFF,
            'parent_id' => 10,
            'seo_role' => User::SEO_ROLE_PLANNER,
            'status' => User::STATUS_NORMAL,
        ]));

        $this->assertTrue(SeoAccessControl::canDeleteSeoMedia());
    }

    protected function tearDown(): void
    {
        Auth::logout();

        parent::tearDown();
    }
}
