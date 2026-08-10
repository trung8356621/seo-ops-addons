<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\TeamChatNotificationService;
use App\Models\TeamMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class TeamChatNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_notify_workspace_members_excludes_sender(): void
    {
        if (! Schema::hasTable('notifications') || ! Schema::hasTable('team_messages')) {
            $this->markTestSkipped('Required tables are not available.');
        }

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner-chat@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
            'seo_role' => User::SEO_ROLE_MANAGER,
        ]);

        $staff = User::query()->create([
            'name' => 'Staff',
            'email' => 'staff-chat@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_STAFF,
            'parent_id' => $owner->id,
            'status' => User::STATUS_NORMAL,
            'seo_role' => User::SEO_ROLE_PLANNER,
        ]);

        $sender = User::query()->create([
            'name' => 'Sender',
            'email' => 'sender-chat@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_STAFF,
            'parent_id' => $owner->id,
            'status' => User::STATUS_NORMAL,
            'seo_role' => User::SEO_ROLE_CONTENT_MANAGER,
        ]);

        $message = TeamMessage::query()->create([
            'owner_id' => $owner->id,
            'user_id' => $sender->id,
            'message' => 'Xin chào team',
        ]);

        app(TeamChatNotificationService::class)->notifyWorkspaceMembers($message);

        $this->assertSame(
            2,
            (int) $owner->fresh()->notifications()->count() + (int) $staff->fresh()->notifications()->count(),
        );
        $this->assertSame(0, (int) $sender->fresh()->notifications()->count());
    }
}
