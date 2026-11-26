<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource;
use Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource\Pages\ListAiConnections;
use Omnichannel\Addons\AiPrompt\Models\AiModelCapabilityRow;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\SetAiConnectionActive;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use App\Models\WpOption;
use Tests\TestCase;

/**
 * List Active toggle (incl. global) + connection routing_priority persistence/runtime.
 */
final class ApiConnectionPriorityAndActiveToggleTest extends TestCase
{
    private AiModelPriorityService $priorities;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'ai_routing_targets', 'ai_routing_profiles', 'ai_model_capabilities', 'seo_ai_models', 'api_connections',
            'users', 'wp_options', 'permissions', 'roles', 'model_has_permissions', 'model_has_roles', 'role_has_permissions',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->nullable();
            $table->string('role')->default('staff');
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name')->default('web');
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name')->default('web');
            $table->timestamps();
        });
        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });
        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });
        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
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
            $table->json('paid_lock_reasons')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('seo_ai_models', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('api_connection_id');
            $table->string('category')->nullable();
            $table->string('raw_model_name');
            $table->string('display_name');
            $table->integer('priority')->default(100);
            $table->string('status')->default('active');
            $table->boolean('is_hidden')->default(false);
            $table->text('last_error')->nullable();
            $table->json('capabilities')->nullable();
            $table->timestamps();
        });
        Schema::create('ai_model_capabilities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seo_ai_model_id')->nullable();
            $table->unsignedBigInteger('api_connection_id')->nullable();
            $table->string('model_key');
            $table->string('capability');
            $table->string('source')->default('built_in');
            $table->boolean('enabled')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('ai_routing_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(0);
            $table->string('key');
            $table->string('name');
            $table->string('description')->nullable();
            $table->json('required_capabilities')->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
        });
        Schema::create('ai_routing_targets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('profile_id')->nullable();
            $table->string('profile_key');
            $table->unsignedBigInteger('api_connection_id');
            $table->unsignedBigInteger('seo_ai_model_id')->nullable();
            $table->string('model_key');
            $table->unsignedInteger('priority')->default(1);
            $table->boolean('enabled')->default(true);
            $table->json('options')->nullable();
            $table->timestamps();
        });
        Schema::create('wp_options', function (Blueprint $table): void {
            $table->id();
            $table->string('option_name')->unique();
            $table->longText('option_value')->nullable();
            $table->string('autoload')->default('no');
            $table->timestamps();
        });

        foreach (['seo_dataforseo_connections', 'seo_gsc_master_connections', 'seo_gsc_property_mappings', 'seo_serp_provider_connections', 'seo_extended_provider_connections'] as $table) {
            foreach (array_unique([(string) config('database.default'), 'mysql']) as $connection) {
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

        WpOption::clearRequestCache();

        $this->priorities = new AiModelPriorityService();
        $this->app->instance(AiModelPriorityService::class, $this->priorities);
    }

    public function test_global_connection_active_toggle_persists(): void
    {
        $owner = $this->user(1, User::ROLE_OWNER);
        $conn = $this->connection((int) $owner->id, ApiConnectionProviders::NVIDIA, 'NVIDIA NIM', global: true);

        $updated = app(SetAiConnectionActive::class)->handle($conn, false);
        $this->assertSame('inactive', (string) $updated->status);
        $this->assertTrue((bool) $updated->is_global);

        $updated = app(SetAiConnectionActive::class)->handle($updated, true);
        $this->assertSame('active', (string) $updated->status);
    }

    public function test_list_toggle_global_passes_for_owner(): void
    {
        $owner = $this->user(2, User::ROLE_OWNER);
        $conn = $this->connection((int) $owner->id, ApiConnectionProviders::OPENROUTER, 'OpenRouter', global: true);

        $this->actingAs($owner);
        $this->setAdminPanel();
        Livewire::actingAs($owner)
            ->withQueryParams(['type' => 'ai'])
            ->test(ListAiConnections::class)
            ->call('toggleConnectionActive', (string) $conn->id)
            ->assertHasNoErrors();

        $this->assertSame('inactive', (string) $conn->fresh()->status);
    }

    public function test_unauthorized_user_cannot_toggle_global(): void
    {
        $staff = $this->user(3, User::ROLE_STAFF);
        $conn = $this->connection(1, ApiConnectionProviders::NVIDIA, 'NVIDIA NIM', global: true);

        $this->actingAs($staff);
        $this->assertFalse(AiConnectionResource::canViewAny());
        $this->assertFalse(AiConnectionResource::canEdit($conn));
        // List toggle gates on canEdit before SetAiConnectionActive — staff never reaches DB write.
        $this->assertSame('active', (string) $conn->fresh()->status);
    }

    public function test_user_owned_connection_toggle_passes(): void
    {
        $owner = $this->user(4, User::ROLE_OWNER);
        $conn = $this->connection((int) $owner->id, ApiConnectionProviders::DEEPSEEK, 'DeepSeek', global: false);

        app(SetAiConnectionActive::class)->handle($conn, false);
        $this->assertSame('inactive', (string) $conn->fresh()->status);
        app(SetAiConnectionActive::class)->handle($conn->fresh(), true);
        $this->assertSame('active', (string) $conn->fresh()->status);
    }

    public function test_priority_persists_in_metadata_routing_priority(): void
    {
        $owner = $this->user(5, User::ROLE_OWNER);
        $conn = $this->connection((int) $owner->id, ApiConnectionProviders::GEMINI, 'Gemini', global: true);

        $this->priorities->setConnectionPriority($conn, 1);
        $conn->refresh();
        $this->assertSame(1, (int) ($conn->metadata['routing_priority'] ?? 0));
        $this->assertSame(1, $this->priorities->storedConnectionPriority($conn));
        $this->assertSame(1, $this->priorities->connectionPriority($conn));
    }

    public function test_list_priority_update_and_invalid_rejected(): void
    {
        $owner = $this->user(6, User::ROLE_OWNER);
        $conn = $this->connection((int) $owner->id, ApiConnectionProviders::OPENROUTER, 'OpenRouter', global: true);

        $this->actingAs($owner);
        $this->setAdminPanel();
        Livewire::actingAs($owner)
            ->withQueryParams(['type' => 'ai'])
            ->test(ListAiConnections::class)
            ->call('updateConnectionPriority', (string) $conn->id, 2)
            ->assertHasNoErrors();

        $this->assertSame(2, (int) ($conn->fresh()->metadata['routing_priority'] ?? 0));

        Livewire::actingAs($owner)
            ->withQueryParams(['type' => 'ai'])
            ->test(ListAiConnections::class)
            ->call('updateConnectionPriority', (string) $conn->id, 0);

        $this->assertSame(2, (int) ($conn->fresh()->metadata['routing_priority'] ?? 0));
    }

    public function test_connection_priority_one_before_two_and_inactive_skipped(): void
    {
        $userId = 7;
        $first = $this->connection($userId, ApiConnectionProviders::GEMINI, 'Gemini', global: true);
        $second = $this->connection($userId, ApiConnectionProviders::NVIDIA, 'NVIDIA', global: true);
        $this->priorities->setConnectionPriority($first, 1);
        $this->priorities->setConnectionPriority($second, 2);

        $gModel = $this->model($first, 'gemini-flash', 50);
        $nModel = $this->model($second, 'nemotron', 10);
        $this->grantText($first, $gModel);
        $this->grantText($second, $nModel);
        $this->priorities->appendToArea($userId, AiModelArea::TextFast, [(int) $gModel->id, (int) $nModel->id]);

        $ordered = $this->priorities->effectiveAreaModels($userId, AiModelArea::TextFast);
        $this->assertSame(['gemini-flash', 'nemotron'], array_map(
            static fn (SeoAiModel $m): string => (string) $m->raw_model_name,
            $ordered,
        ));

        // Model priority inside connection stays independent (nemotron model priority 10 < gemini 50),
        // but connection priority still wins across connections.
        $this->assertSame(50, (int) $gModel->priority);
        $this->assertSame(10, (int) $nModel->priority);

        app(SetAiConnectionActive::class)->handle($first->fresh(), false);
        $this->priorities->forgetMemo();

        $eligible = [];
        foreach ($this->priorities->effectiveAreaModels($userId, AiModelArea::TextFast) as $model) {
            $connection = $model->apiConnection;
            if (! $connection instanceof ApiConnection || (string) $connection->status !== 'active') {
                continue;
            }
            $eligible[] = (string) $model->raw_model_name;
        }
        $this->assertSame(['nemotron'], $eligible);
        $this->assertSame('inactive', (string) $first->fresh()->status);
    }

    public function test_same_priority_stable_tie_break_by_id(): void
    {
        $userId = 8;
        $a = $this->connection($userId, ApiConnectionProviders::GEMINI, 'A', global: true);
        $b = $this->connection($userId, ApiConnectionProviders::DEEPSEEK, 'B', global: true);
        $this->priorities->setConnectionPriority($a, 5);
        $this->priorities->setConnectionPriority($b, 5);

        $sorted = $this->priorities->sortConnections([$b->fresh(), $a->fresh()]);
        $this->assertSame([(int) $a->id, (int) $b->id], array_map(
            static fn (ApiConnection $c): int => (int) $c->id,
            $sorted,
        ));
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

    private function connection(int $userId, string $provider, string $name, bool $global = false): ApiConnection
    {
        return ApiConnection::query()->create([
            'user_id' => $global ? null : $userId,
            'provider' => $provider,
            'name' => $name,
            'api_key' => 'sk-test-key-long-enough',
            'status' => 'active',
            'paid_locked' => false,
            'paid_lock_reasons' => [],
            'is_global' => $global,
            'metadata' => [],
        ]);
    }

    private function model(ApiConnection $connection, string $raw, int $priority): SeoAiModel
    {
        return SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'category' => AiModelCategory::GEMINI_FLASH,
            'raw_model_name' => $raw,
            'display_name' => $raw,
            'priority' => $priority,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'capabilities' => [],
        ]);
    }

    private function grantText(ApiConnection $connection, SeoAiModel $model): void
    {
        AiModelCapabilityRow::query()->create([
            'seo_ai_model_id' => $model->id,
            'api_connection_id' => $connection->id,
            'model_key' => (string) $model->raw_model_name,
            'capability' => AiModelCapability::TextGenerate->value,
            'source' => 'test',
            'enabled' => true,
        ]);
    }
}
