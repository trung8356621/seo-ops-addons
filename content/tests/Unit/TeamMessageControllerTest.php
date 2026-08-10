<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use App\Models\TeamMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class TeamMessageControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unread_summary_returns_json_counts(): void
    {
        if (! Schema::hasTable('team_messages')) {
            $this->markTestSkipped('team_messages table is not available.');
        }

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner-team-sse@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
            'seo_role' => User::SEO_ROLE_MANAGER,
        ]);

        $staff = User::query()->create([
            'name' => 'Staff',
            'email' => 'staff-team-sse@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_STAFF,
            'parent_id' => $owner->id,
            'status' => User::STATUS_NORMAL,
            'seo_role' => User::SEO_ROLE_PLANNER,
        ]);

        TeamMessage::query()->create([
            'owner_id' => $owner->id,
            'user_id' => $staff->id,
            'message' => 'Tin nhắn 1',
        ]);

        $messageTwo = TeamMessage::query()->create([
            'owner_id' => $owner->id,
            'user_id' => $staff->id,
            'message' => 'Tin nhắn 2',
        ]);

        $response = $this->actingAs($owner)->getJson('/api/seo/team/messages?unread_summary=1&since_id=0');

        $response->assertOk();
        $response->assertJson([
            'unread_count' => 2,
            'latest_message_id' => $messageTwo->id,
            'owner_id' => $owner->id,
        ]);
    }

    public function test_poll_returns_json_message_delta(): void
    {
        if (! Schema::hasTable('team_messages')) {
            $this->markTestSkipped('team_messages table is not available.');
        }

        $owner = User::query()->create([
            'name' => 'Owner Poll',
            'email' => 'owner-team-poll@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
            'seo_role' => User::SEO_ROLE_MANAGER,
        ]);

        $first = TeamMessage::query()->create([
            'owner_id' => $owner->id,
            'user_id' => $owner->id,
            'message' => 'History',
        ]);

        $second = TeamMessage::query()->create([
            'owner_id' => $owner->id,
            'user_id' => $owner->id,
            'message' => 'Delta',
        ]);

        $history = $this->actingAs($owner)->getJson('/api/seo/team/messages?poll=1&after_id=0');
        $history->assertOk();
        $history->assertJsonPath('history_end', true);
        $history->assertJsonPath('messages.0.id', $first->id);
        $history->assertJsonPath('messages.1.id', $second->id);

        $delta = $this->actingAs($owner)->getJson('/api/seo/team/messages?poll=1&after_id='.$first->id);
        $delta->assertOk();
        $delta->assertJsonPath('history_end', false);
        $delta->assertJsonCount(1, 'messages');
        $delta->assertJsonPath('messages.0.id', $second->id);
    }
}
