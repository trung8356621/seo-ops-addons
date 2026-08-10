<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\User;
use Tests\TestCase;

final class SeoAccessControlAccountOwnerTest extends TestCase
{
    public function test_staff_resolves_parent_owner_id(): void
    {
        $staff = new User([
            'role' => User::ROLE_STAFF,
            'parent_id' => 7,
        ]);
        $staff->id = 42;
        $staff->exists = true;

        $this->actingAs($staff);

        $this->assertSame(7, SeoAccessControl::accountOwnerId());
    }

    public function test_owner_uses_own_id(): void
    {
        $owner = new User([
            'role' => User::ROLE_OWNER,
            'parent_id' => null,
        ]);
        $owner->id = 7;
        $owner->exists = true;

        $this->actingAs($owner);

        $this->assertSame(7, SeoAccessControl::accountOwnerId());
    }
}
