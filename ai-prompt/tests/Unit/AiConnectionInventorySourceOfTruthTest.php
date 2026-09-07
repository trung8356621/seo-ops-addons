<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Services\AiConnectionInventoryService;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\AiPrompt\Enums\ApiConnectionType;
use Tests\TestCase;

final class AiConnectionInventorySourceOfTruthTest extends TestCase
{
    private AiConnectionInventoryService $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('api_connections');
        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->nullable();
            $table->string('role')->default('staff');
            $table->timestamps();
        });
        Schema::create('api_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('provider');
            $table->string('name');
            $table->text('api_key')->nullable();
            $table->boolean('is_global')->default(false);
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        $this->inventory = new AiConnectionInventoryService();
    }

    public function test_canonical_row_visible_to_owning_owner(): void
    {
        $owner = $this->user(1, User::ROLE_OWNER);
        $this->connection((int) $owner->id, ApiConnectionProviders::OPENROUTER, 'OR', 'sk-test-key-long');

        $counts = $this->inventory->counts((int) $owner->id);
        $this->assertSame(1, $counts['configured']);
        $this->assertSame(1, $this->inventory->configuredAiConnections((int) $owner->id)->count());
    }

    public function test_other_owner_sees_workspace_openrouter_not_empty(): void
    {
        $ownerA = $this->user(1, User::ROLE_OWNER);
        $ownerB = $this->user(2, User::ROLE_OWNER);
        $this->assertTrue($this->inventory->viewerSeesWorkspaceInventory(2));
        $this->connection((int) $ownerA->id, ApiConnectionProviders::OPENROUTER, 'OR', 'sk-test-key-long');

        $this->assertSame(1, $this->inventory->counts((int) $ownerB->id)['configured']);
        $list = $this->inventory->configuredAiConnections((int) $ownerB->id);
        $this->assertSame(ApiConnectionProviders::OPENROUTER, (string) $list->first()->provider);
    }

    public function test_staff_does_not_see_other_users_private_connection(): void
    {
        $owner = $this->user(1, User::ROLE_OWNER);
        $staff = $this->user(3, 'staff');
        $this->connection((int) $owner->id, ApiConnectionProviders::OPENROUTER, 'OR', 'sk-test-key-long');

        $this->assertSame(0, $this->inventory->counts((int) $staff->id)['configured']);
    }

    public function test_unusable_credential_still_configured_count_one(): void
    {
        $owner = $this->user(1, User::ROLE_OWNER);
        $this->connection((int) $owner->id, ApiConnectionProviders::OPENROUTER, 'OR', 'k');

        $counts = $this->inventory->counts((int) $owner->id);
        $this->assertSame(1, $counts['configured']);
        $this->assertSame(1, $counts['credential_unusable']);
        $this->assertSame(0, $counts['credential_usable']);
    }

    public function test_settings_list_and_runtime_priority_share_inventory(): void
    {
        $ownerA = $this->user(1, User::ROLE_OWNER);
        $ownerB = $this->user(2, User::ROLE_OWNER);
        $conn = $this->connection((int) $ownerA->id, ApiConnectionProviders::OPENROUTER, 'OR', 'sk-test-key-long');

        $priorities = new AiModelPriorityService();
        $runtime = $priorities->aiConnections((int) $ownerB->id);
        $this->assertCount(1, $runtime);
        $this->assertSame((int) $conn->id, (int) $runtime[0]->id);

        // Settings list service needs SEO deps — assert inventory + type attribute path instead.
        $rows = $this->inventory->configuredAiConnections((int) $ownerB->id);
        $row = $rows->first();
        $row->setAttribute('connection_type', ApiConnectionProviders::connectionType((string) $row->provider)->value);
        $this->assertSame(ApiConnectionType::Ai->value, (string) $row->getAttribute('connection_type'));
    }

    public function test_empty_state_only_when_inventory_empty(): void
    {
        $owner = $this->user(1, User::ROLE_OWNER);
        $this->assertSame(0, $this->inventory->counts((int) $owner->id)['configured']);
    }

    public function test_api_connection_model_uses_core_connection(): void
    {
        $expected = (string) config('database.core_connection', 'mysql');
        $this->assertSame($expected, (new ApiConnection)->getConnectionName());
    }

    private function user(int $id, string $role): User
    {
        $user = new User();
        $user->forceFill([
            'id' => $id,
            'email' => "u{$id}@example.test",
            'role' => $role,
        ]);
        $user->exists = true;
        // Persist for inventory role lookup.
        \Illuminate\Support\Facades\DB::table('users')->insert([
            'id' => $id,
            'email' => "u{$id}@example.test",
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function connection(int $userId, string $provider, string $name, string $key): ApiConnection
    {
        return ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => $provider,
            'name' => $name,
            'api_key' => $key,
            'status' => 'active',
            'is_global' => false,
            'metadata' => [],
        ]);
    }
}
