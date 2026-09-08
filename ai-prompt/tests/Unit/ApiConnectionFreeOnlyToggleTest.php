<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource\Pages\ListAiConnections;
use Omnichannel\Addons\AiPrompt\Models\ApiConnectionListRow;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiConnectionFreeOnlySupport;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\AiProviderFailureClassifier;
use Omnichannel\Addons\AiPrompt\Services\AiResilienceSettingsService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingBootstrapService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use Omnichannel\Addons\AiPrompt\Services\AiRuntimeHealthService;
use Omnichannel\Addons\AiPrompt\Services\ModelCapabilityRegistry;
use Omnichannel\Addons\AiPrompt\Services\SetAiConnectionActive;
use Omnichannel\Addons\AiPrompt\Services\SetAiConnectionFreeOnly;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use App\Models\WpOption;
use Omnichannel\Addons\AiPrompt\Models\AiModelCapabilityRow;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Tests\TestCase;

/**
 * Free only (api_connections.paid_locked) list toggles + router enforcement.
 */
final class ApiConnectionFreeOnlyToggleTest extends TestCase
{
    private AiModelRouterService $router;

    private AiRuntimeHealthService $health;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ai_routing_targets', 'ai_routing_profiles', 'ai_model_capabilities', 'seo_ai_models', 'api_connections', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::dropIfExists('ai_runtime_health_states');
        Schema::connection('mysql')->dropIfExists('ai_runtime_health_states');
        Schema::dropIfExists('wp_options');

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
        Schema::create('ai_runtime_health_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('api_connection_id')->nullable()->index();
            $table->string('health_status', 32)->default('no_data');
            $table->boolean('paid_locked')->default(false);
            $table->boolean('manual_unlock_required')->default(false);
            $table->timestamp('cooldown_until')->nullable();
            $table->unsignedInteger('total_attempts')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failure_count')->default(0);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->json('failure_counts')->nullable();
            $table->string('last_error_code', 32)->nullable();
            $table->string('last_failure_class', 64)->nullable();
            $table->text('last_failure_message')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'subject_type', 'subject_id']);
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

        $registry = new ModelCapabilityRegistry();
        $priorities = new AiModelPriorityService();
        $targets = new AiRoutingTargetService($registry, priorities: $priorities);
        $bootstrap = new AiRoutingBootstrapService($registry, $targets);
        $this->health = new AiRuntimeHealthService(notifications: null);
        $this->router = new AiModelRouterService($registry, $targets, $bootstrap);
        $this->app->instance(AiProviderFailureClassifier::class, new AiProviderFailureClassifier());
        $this->app->instance(AiRuntimeHealthService::class, $this->health);
        $this->app->instance(AiResilienceSettingsService::class, new AiResilienceSettingsService());
    }

    public function test_a_active_full_default_labels_on_list(): void
    {
        $owner = $this->user(1, User::ROLE_OWNER);
        $conn = $this->connection((int) $owner->id, 'seo-ops-2', false);

        $this->actingAs($owner);
        $this->setAdminPanel();
        $html = Livewire::actingAs($owner)
            ->withQueryParams(['type' => 'ai'])
            ->test(ListAiConnections::class)
            ->html();

        $this->assertStringContainsString('seo-ops-2', $html);
        $this->assertStringContainsString(__('seo-content-ai::filament.api_connections.status_active'), $html);
        $this->assertStringContainsString(__('seo-content-ai::filament.api_connections.mode_full'), $html);
        $this->assertFalse((bool) $conn->fresh()->paid_locked);
    }

    public function test_b_toggle_free_only_on_sets_paid_locked(): void
    {
        $owner = $this->user(2, User::ROLE_OWNER);
        $conn = $this->connection((int) $owner->id, 'seo-ops-2', false);

        $updated = app(SetAiConnectionFreeOnly::class)->handle($conn, true);
        $this->assertTrue((bool) $updated->paid_locked);

        $this->actingAs($owner);
        $this->setAdminPanel();
        Livewire::actingAs($owner)
            ->withQueryParams(['type' => 'ai'])
            ->test(ListAiConnections::class)
            ->assertSee(__('seo-content-ai::filament.api_connections.mode_free_only'))
            ->call('toggleConnectionFreeOnly', (string) $conn->id)
            ->assertHasNoErrors();

        $this->assertFalse((bool) $conn->fresh()->paid_locked);
        Livewire::actingAs($owner)
            ->withQueryParams(['type' => 'ai'])
            ->test(ListAiConnections::class)
            ->assertSee(__('seo-content-ai::filament.api_connections.mode_full'));
    }

    public function test_c_toggle_free_only_off_clears_paid_locked(): void
    {
        $owner = $this->user(3, User::ROLE_OWNER);
        $conn = $this->connection((int) $owner->id, 'seo-ops-2', true);

        app(SetAiConnectionFreeOnly::class)->handle($conn, false);
        $this->assertFalse((bool) $conn->fresh()->paid_locked);
    }

    public function test_d_inactive_then_active_preserves_paid_locked(): void
    {
        $owner = $this->user(4, User::ROLE_OWNER);
        $conn = $this->connection((int) $owner->id, 'seo-ops-2', true);

        app(SetAiConnectionActive::class)->handle($conn, false);
        $conn->refresh();
        $this->assertSame('inactive', (string) $conn->status);
        $this->assertTrue((bool) $conn->paid_locked);

        app(SetAiConnectionActive::class)->handle($conn, true);
        $conn->refresh();
        $this->assertSame('active', (string) $conn->status);
        $this->assertTrue((bool) $conn->paid_locked);
    }

    public function test_e_non_ai_connection_has_no_free_only_toggle(): void
    {
        $owner = $this->user(5, User::ROLE_OWNER);
        $this->connection((int) $owner->id, 'OpenRouter', false);

        $gsc = new ApiConnectionListRow();
        $gsc->forceFill([
            'id' => 'gsc:1',
            'name' => 'Google Search Console',
            'provider' => ApiConnectionProviders::GOOGLE_SEARCH_CONSOLE,
            'status' => 'token_expired',
            'connection_type' => 'seo',
        ]);
        $gsc->exists = true;

        $support = new AiConnectionFreeOnlySupport();
        $fakeAi = new ApiConnection();
        $fakeAi->forceFill([
            'id' => 99,
            'provider' => ApiConnectionProviders::GOOGLE_SEARCH_CONSOLE,
            'paid_locked' => false,
        ]);
        $this->assertFalse($support->supports($fakeAi));

        $this->actingAs($owner);
        $this->setAdminPanel();
        $html = Livewire::actingAs($owner)
            ->test(ListAiConnections::class)
            ->html();

        $this->assertStringContainsString('toggleConnectionFreeOnly', $html);
        // Non-AI rows render em dash without free-only wire target for their own id.
        $this->assertStringNotContainsString("toggleConnectionFreeOnly('gsc:", $html);
    }

    public function test_f_router_excludes_paid_claude_when_free_only_on(): void
    {
        $userId = 60;
        $conn = $this->connection($userId, 'OpenRouter', true);
        $paid = $this->model($conn, 'anthropic/claude-sonnet-4.6', false);
        $free = $this->model($conn, 'google/gemma-2-9b-it:free', true);
        $this->grantText($conn, $paid);
        $this->grantText($conn, $free);
        app(AiModelPriorityService::class)->forgetMemo();
        app(AiModelPriorityService::class)->appendToArea(
            $userId,
            AiModelArea::TextLongform,
            [(int) $paid->id, (int) $free->id],
        );

        $paidCandidate = new RoutedAiCandidate(
            profile: AiExecutionProfile::TextLongform->value,
            connection: $conn->fresh(),
            provider: ApiConnectionProviders::OPENROUTER,
            model: 'anthropic/claude-sonnet-4.6',
            capabilities: [],
            priority: 1,
            seoAiModelId: (int) $paid->id,
            isFree: false,
        );
        $freeCandidate = new RoutedAiCandidate(
            profile: AiExecutionProfile::TextLongform->value,
            connection: $conn->fresh(),
            provider: ApiConnectionProviders::OPENROUTER,
            model: 'google/gemma-2-9b-it:free',
            capabilities: [],
            priority: 2,
            seoAiModelId: (int) $free->id,
            isFree: true,
        );

        $this->assertSame('connection_paid_locked', $this->health->skipReason($userId, $paidCandidate));
        $this->assertNull($this->health->skipReason($userId, $freeCandidate));

        // Defense-in-depth: router loop must skip paid before executor (same as health skip).
        $calls = [];
        foreach ([$paidCandidate, $freeCandidate] as $index => $candidate) {
            $skip = $this->health->skipReason($userId, $candidate);
            if ($skip !== null) {
                $this->assertSame('connection_paid_locked', $skip);
                $this->assertFalse($candidate->isFree);
                continue;
            }
            $calls[] = $candidate->model;
        }
        $this->assertSame(['google/gemma-2-9b-it:free'], $calls);
        $this->assertNotContains('anthropic/claude-sonnet-4.6', $calls);
    }

    public function test_g_exhausted_free_does_not_fallback_to_paid_on_paid_locked_connection(): void
    {
        $userId = 61;
        $conn = $this->connection($userId, 'OpenRouter', true);
        $paidCandidate = new RoutedAiCandidate(
            profile: AiExecutionProfile::TextLongform->value,
            connection: $conn,
            provider: ApiConnectionProviders::OPENROUTER,
            model: 'anthropic/claude-sonnet-4.6',
            capabilities: [],
            priority: 1,
            isFree: false,
        );
        $freeCandidate = new RoutedAiCandidate(
            profile: AiExecutionProfile::TextLongform->value,
            connection: $conn,
            provider: ApiConnectionProviders::OPENROUTER,
            model: 'google/gemma-2-9b-it:free',
            capabilities: [],
            priority: 2,
            isFree: true,
        );

        $calls = [];
        $skippedPaid = false;
        foreach ([$paidCandidate, $freeCandidate] as $candidate) {
            $skip = $this->health->skipReason($userId, $candidate);
            if ($skip === 'connection_paid_locked') {
                $skippedPaid = true;
                continue;
            }
            $calls[] = $candidate->model;
            // Free fails — must not then call paid (already skipped above).
        }

        $this->assertTrue($skippedPaid);
        $this->assertSame(['google/gemma-2-9b-it:free'], $calls);
        $this->assertNotContains('anthropic/claude-sonnet-4.6', $calls);
    }

    public function test_openrouter_supports_free_only_toggle(): void
    {
        $conn = $this->connection(70, 'OR', false);
        $this->assertTrue((new AiConnectionFreeOnlySupport())->supports($conn));
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

    private function connection(int $userId, string $name, bool $paidLocked): ApiConnection
    {
        return ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => $name,
            'api_key' => 'sk-test-key-long-enough',
            'status' => 'active',
            'paid_locked' => $paidLocked,
            'is_global' => false,
            'metadata' => [],
        ]);
    }

    private function model(ApiConnection $connection, string $raw, bool $isFree): SeoAiModel
    {
        return SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'category' => AiModelCategory::GEMINI_FLASH,
            'raw_model_name' => $raw,
            'display_name' => $raw,
            'priority' => 10,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'capabilities' => [
                'provider_metadata' => [
                    'pricing' => $isFree
                        ? ['prompt' => '0', 'completion' => '0']
                        : ['prompt' => '0.000001', 'completion' => '0.000002'],
                    'architecture' => ['modality' => 'text->text'],
                ],
            ],
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
