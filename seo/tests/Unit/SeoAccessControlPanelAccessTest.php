<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\User;
use PHPUnit\Framework\TestCase;

final class SeoAccessControlPanelAccessTest extends TestCase
{
    public function test_owner_can_access_seo_panel(): void
    {
        $user = new User([
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);

        $this->assertTrue(SeoAccessControl::canAccessSeoPanel($user));
    }

    public function test_staff_team_member_can_access_seo_panel(): void
    {
        $user = new User([
            'role' => User::ROLE_STAFF,
            'parent_id' => 10,
            'seo_role' => User::SEO_ROLE_CONTENT_MANAGER,
            'status' => User::STATUS_NORMAL,
        ]);

        $this->assertTrue(SeoAccessControl::canAccessSeoPanel($user));
    }

    public function test_staff_without_owner_link_cannot_access_seo_panel(): void
    {
        $user = new User([
            'role' => User::ROLE_STAFF,
            'parent_id' => 0,
            'seo_role' => User::SEO_ROLE_CONTENT_MANAGER,
            'status' => User::STATUS_NORMAL,
        ]);

        $this->assertFalse(SeoAccessControl::canAccessSeoPanel($user));
    }

    public function test_blocked_user_cannot_access_seo_panel(): void
    {
        $user = new User([
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_BLOCK,
        ]);

        $this->assertFalse(SeoAccessControl::canAccessSeoPanel($user));
    }
}
