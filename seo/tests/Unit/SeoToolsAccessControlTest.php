<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Support\SeoToolsAccessControl;
use App\Models\ApiConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SeoToolsAccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('api_connections')) {
            $this->markTestSkipped('api_connections table is not available.');
        }
    }

    public function test_owner_with_api_connection_can_use_translate(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner-tools@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);

        ApiConnection::query()->create([
            'user_id' => $owner->id,
            'name' => 'Gemini',
            'provider' => 'gemini',
            'api_key' => 'test-key',
            'status' => 'active',
        ]);

        $this->actingAs($owner);

        $this->assertTrue(SeoToolsAccessControl::canUseTranslateTool());
    }

    public function test_staff_of_owner_with_api_connection_can_use_translate(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner-tools-staff@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);

        ApiConnection::query()->create([
            'user_id' => $owner->id,
            'name' => 'Gemini',
            'provider' => 'gemini',
            'api_key' => 'test-key',
            'status' => 'active',
        ]);

        $staff = User::query()->create([
            'name' => 'Staff',
            'email' => 'staff-tools@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_STAFF,
            'parent_id' => $owner->id,
            'status' => User::STATUS_NORMAL,
        ]);

        $this->actingAs($staff);

        $this->assertTrue(SeoToolsAccessControl::canUseTranslateTool());
    }

    public function test_guest_cannot_use_translate(): void
    {
        $this->assertFalse(SeoToolsAccessControl::canUseTranslateTool());
    }

    public function test_owner_without_api_connection_cannot_use_translate(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner-no-ai@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);

        $this->actingAs($owner);

        $this->assertFalse(SeoToolsAccessControl::canUseTranslateTool());
    }
}
