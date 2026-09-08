<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Omnichannel\Addons\AiPrompt\Enums\ApiConnectionType;
use Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource\Pages\ListAiConnections;
use Omnichannel\Addons\AiPrompt\Services\AiConnectionInventoryService;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Tests\TestCase;

/**
 * Acceptance: admin Settings API list (?type=ai) must show configured OpenRouter.
 * Empty-state text must not appear when a canonical AI connection exists.
 */
final class AdminApiConnectionsSettingsAcceptanceTest extends TestCase
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
            $table->boolean('paid_locked')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        $this->inventory = new AiConnectionInventoryService();

        // List service probes SEO provider models that hard-code connection=mysql.
        // In phpunit, mysql is a separate :memory: handle from default — stub on both.
        $seoTables = [
            'seo_dataforseo_connections',
            'seo_gsc_master_connections',
            'seo_gsc_property_mappings',
            'seo_serp_provider_connections',
            'seo_extended_provider_connections',
        ];
        foreach (array_unique([(string) config('database.default'), 'mysql']) as $connection) {
            foreach ($seoTables as $table) {
                Schema::connection($connection)->dropIfExists($table);
                Schema::connection($connection)->create($table, function (Blueprint $blueprint) use ($table): void {
                    $blueprint->id();
                    $blueprint->unsignedBigInteger('user_id')->nullable();
                    $blueprint->boolean('is_global')->default(false);
                    $blueprint->string('provider')->nullable();
                    $blueprint->string('name')->nullable();
                    $blueprint->string('status')->nullable();
                    $blueprint->string('login')->nullable();
                    $blueprint->text('password')->nullable();
                    $blueprint->text('api_key')->nullable();
                    $blueprint->json('metadata')->nullable();
                    $blueprint->timestamps();
                    if ($table === 'seo_gsc_property_mappings') {
                        $blueprint->unsignedBigInteger('site_id')->nullable();
                        $blueprint->unsignedBigInteger('gsc_connection_id')->nullable();
                    }
                });
            }
        }
    }

    public function test_admin_settings_type_ai_renders_openrouter_not_empty_state(): void
    {
        $owner = $this->user(1, User::ROLE_OWNER);
        $this->connection((int) $owner->id, ApiConnectionProviders::OPENROUTER, 'OR 712', 'sk-test-key-long', 'active');

        $this->actingAs($owner);
        $this->setAdminPanel();

        $component = Livewire::actingAs($owner)
            ->withQueryParams(['type' => 'ai'])
            ->test(ListAiConnections::class);

        $html = $component->html();
        $this->assertStringNotContainsString('Chưa cấu hình kết nối API nào.', $html);
        $this->assertTrue(
            str_contains($html, 'OpenRouter') || str_contains($html, 'OR 712'),
            'OpenRouter must render on admin Settings API list',
        );

        $records = $component->instance()->getTableRecords();
        $this->assertGreaterThanOrEqual(1, $records->count());
        $this->assertTrue(
            $records->contains(
                fn ($row) => (string) $row->getAttribute('provider') === ApiConnectionProviders::OPENROUTER,
            ),
        );
    }

    public function test_unhealthy_openrouter_still_visible_in_settings(): void
    {
        $owner = $this->user(1, User::ROLE_OWNER);
        $conn = $this->connection((int) $owner->id, ApiConnectionProviders::OPENROUTER, 'OR broken', 'x', 'active');
        $conn->metadata = ['health' => 'invalid_credential', 'auto_disabled' => true];
        $conn->save();

        $rows = $this->inventory->configuredAiConnections((int) $owner->id);
        $this->assertCount(1, $rows);
        $this->assertSame((int) $conn->id, (int) $rows->first()->id);
        $this->assertSame(ApiConnectionType::Ai->value, (string) $rows->first()->getAttribute('connection_type'));

        $this->actingAs($owner);
        $this->setAdminPanel();
        $component = Livewire::actingAs($owner)
            ->withQueryParams(['type' => 'ai'])
            ->test(ListAiConnections::class);

        $this->assertStringNotContainsString('Chưa cấu hình kết nối API nào.', $component->html());
        $this->assertTrue(
            $component->instance()->getTableRecords()->contains(
                fn ($row) => (int) $row->getKey() === (int) $conn->id,
            ),
        );
    }

    public function test_canonical_openrouter_visible_when_noncanonical_duplicate_empty(): void
    {
        $owner = $this->user(1, User::ROLE_OWNER);
        $conn = $this->connection((int) $owner->id, ApiConnectionProviders::OPENROUTER, 'OR 712', 'sk-test-key-long', 'active');

        $this->assertSame(1, ApiConnection::query()->count());
        $this->assertSame(1, $this->inventory->configuredAiConnections((int) $owner->id)->count());
        $this->assertSame(
            (int) $conn->id,
            (int) $this->inventory->configuredAiConnections((int) $owner->id)->first()->id,
        );

        $this->actingAs($owner);
        $this->setAdminPanel();
        $component = Livewire::actingAs($owner)
            ->withQueryParams(['type' => 'ai'])
            ->test(ListAiConnections::class);

        $this->assertStringNotContainsString('Chưa cấu hình kết nối API nào.', $component->html());
        $this->assertTrue(
            $component->instance()->getTableRecords()->contains(
                fn ($row) => (int) $row->getKey() === (int) $conn->id,
            ),
        );
    }

    private function setAdminPanel(): void
    {
        if (! class_exists(\Filament\Facades\Filament::class)) {
            return;
        }
        try {
            \Filament\Facades\Filament::setCurrentPanel(
                \Filament\Facades\Filament::getPanel('admin'),
            );
        } catch (\Throwable) {
        }
    }

    private function user(int $id, string $role): User
    {
        DB::table('users')->insert([
            'id' => $id,
            'email' => "u{$id}@example.test",
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = new User();
        $user->forceFill([
            'id' => $id,
            'email' => "u{$id}@example.test",
            'role' => $role,
        ]);
        $user->exists = true;

        return $user;
    }

    private function connection(int $userId, string $provider, string $name, string $key, string $status): ApiConnection
    {
        return ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => $provider,
            'name' => $name,
            'api_key' => $key,
            'status' => $status,
            'is_global' => false,
            'metadata' => [],
        ]);
    }
}
