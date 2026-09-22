<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiCapacityStatusService;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use Omnichannel\Addons\AiPrompt\Services\ModelCapabilityRegistry;
use Omnichannel\Addons\AiPrompt\Services\OpenRouterFreePoolService;
use Omnichannel\Addons\AiPrompt\Services\OpenRouterModelEconomics;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use Tests\TestCase;

/**
 * Capacity rail: workspace health + API-Connections authorization gate.
 */
final class AiCapacityStatusWorkspaceHealthTest extends TestCase
{
    private AiModelPriorityService $priorities;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['seo_ai_models', 'api_connections', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
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
        $this->priorities = new AiModelPriorityService();
        $this->app->instance(AiModelPriorityService::class, $this->priorities);
    }

    public function test_provider_gates_rail_before_status_query(): void
    {
        $src = (string) file_get_contents(
            (string) (new \ReflectionClass(\Omnichannel\Addons\AiPrompt\AiPromptServiceProvider::class))->getFileName(),
        );
        self::assertStringContainsString('viewerMayInspectCapacity', $src);
        self::assertStringContainsString('ai-capacity-rail', $src);

        $serviceSrc = (string) file_get_contents(
            (string) (new \ReflectionClass(AiCapacityStatusService::class))->getFileName(),
        );
        self::assertStringContainsString('AiConnectionResource::canViewAny', $serviceSrc);
        self::assertStringContainsString('resolveWorkspaceCapacityUserId', $serviceSrc);
        self::assertStringContainsString('viewerSeesWorkspaceInventory', $serviceSrc);
    }

    public function test_staff_viewer_resolves_to_workspace_owner_for_capacity(): void
    {
        $this->seedUser(1, User::ROLE_OWNER);
        $this->seedUser(50, 'staff');

        $service = new AiCapacityStatusService();
        self::assertSame(1, $service->resolveWorkspaceCapacityUserId(50));
        self::assertSame(1, $service->resolveWorkspaceCapacityUserId(1));
    }

    public function test_healthy_workspace_stays_healthy_for_staff_viewer_id(): void
    {
        $this->seedUser(1, User::ROLE_OWNER);
        $this->seedUser(50, 'staff');
        $or = $this->connection(1);
        $router = $this->model($or, OpenRouterModelEconomics::FREE_ROUTER_ID, 'OpenRouter Free Pool', free: true);
        $this->model($or, 'vendor/workspace-ok:free', 'Workspace OK', free: true);
        $this->priorities->appendToArea(1, AiModelArea::TextFast, [(int) $router->id]);

        $this->app->instance(AiRoutingTargetService::class, new AiRoutingTargetService(new ModelCapabilityRegistry()));
        $this->app->instance(OpenRouterFreePoolService::class, new OpenRouterFreePoolService());

        $service = new AiCapacityStatusService();
        $asOwner = $service->status(1);
        $asStaff = $service->status(50);

        self::assertSame($asOwner['state'], $asStaff['state']);
        self::assertNotSame(AiCapacityStatusService::STATE_CRITICAL, $asStaff['state']);
        self::assertTrue((bool) $asStaff['free_text_available']);
    }

    public function test_staff_without_personal_connections_is_not_false_critical(): void
    {
        $this->seedUser(1, User::ROLE_OWNER);
        $this->seedUser(50, 'staff');
        $or = $this->connection(1);
        $router = $this->model($or, OpenRouterModelEconomics::FREE_ROUTER_ID, 'OpenRouter Free Pool', free: true);
        $this->model($or, 'vendor/owner-only:free', 'Owner Free', free: true);
        $this->priorities->appendToArea(1, AiModelArea::TextFast, [(int) $router->id]);

        $this->app->instance(AiRoutingTargetService::class, new AiRoutingTargetService(new ModelCapabilityRegistry()));
        $this->app->instance(OpenRouterFreePoolService::class, new OpenRouterFreePoolService());

        // Pre-fix failure pattern: evaluating under staff id alone would see zero connections.
        $inventory = app(\Omnichannel\Addons\AiPrompt\Services\AiConnectionInventoryService::class);
        self::assertSame(0, $inventory->counts(50)['configured']);
        self::assertSame(1, $inventory->counts(1)['configured']);

        $status = (new AiCapacityStatusService())->status(50);
        self::assertNotSame(AiCapacityStatusService::STATE_CRITICAL, $status['state']);
        self::assertTrue((bool) $status['free_text_available']);
    }

    public function test_genuine_critical_when_workspace_has_no_usable_routes(): void
    {
        $this->seedUser(1, User::ROLE_OWNER);
        // No api_connections / models seeded.

        $this->app->instance(AiRoutingTargetService::class, new AiRoutingTargetService(new ModelCapabilityRegistry()));
        $this->app->instance(OpenRouterFreePoolService::class, new OpenRouterFreePoolService());

        $status = (new AiCapacityStatusService())->status(1);
        self::assertSame(AiCapacityStatusService::STATE_CRITICAL, $status['state']);
        self::assertStringContainsString('API Connections', (string) $status['message']);
    }

    public function test_can_view_any_excludes_content_manager_gate_contract(): void
    {
        $resourceSrc = (string) file_get_contents(
            (string) (new \ReflectionClass(
                \Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource::class,
            ))->getFileName(),
        );
        self::assertStringContainsString('canAccessManagerFeatures', $resourceSrc);
        self::assertStringContainsString('canViewAny', $resourceSrc);

        $accessSrc = (string) file_get_contents(
            (string) (new \ReflectionClass(
                \Omnichannel\Addons\Seo\Support\SeoAccessControl::class,
            ))->getFileName(),
        );
        self::assertStringContainsString('ROLE_CONTENT_MANAGER', $accessSrc);
        self::assertStringContainsString('canAccessManagerFeatures', $accessSrc);
        // content_manager rank is below manager → cannot access manager features.
        self::assertMatchesRegularExpression(
            '/ROLE_CONTENT_MANAGER\s*=>\s*1/',
            $accessSrc,
        );
        self::assertMatchesRegularExpression(
            '/ROLE_MANAGER\s*=>\s*3/',
            $accessSrc,
        );
    }

    private function seedUser(int $id, string $role): void
    {
        \Illuminate\Support\Facades\DB::table('users')->insert([
            'id' => $id,
            'email' => "u{$id}@example.test",
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function connection(int $userId): ApiConnection
    {
        return ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => ApiConnectionProviders::OPENROUTER,
            'name' => 'OR',
            'api_key' => 'sk-test-key-long-enough',
            'is_global' => false,
            'status' => 'active',
        ]);
    }

    private function model(ApiConnection $connection, string $raw, string $display, bool $free): SeoAiModel
    {
        $caps = [
            'provider_metadata' => [
                'pricing' => $free
                    ? ['prompt' => '0', 'completion' => '0']
                    : ['prompt' => '0.000001', 'completion' => '0.000002'],
                'architecture' => ['modality' => 'text->text'],
                'context_length' => 32000,
            ],
            'resolved' => ['text.generate', 'text.reasoning'],
        ];

        return SeoAiModel::query()->create([
            'api_connection_id' => (int) $connection->id,
            'category' => AiModelCategory::GEMINI_FLASH,
            'raw_model_name' => $raw,
            'display_name' => $display,
            'priority' => 100,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'is_hidden' => false,
            'capabilities' => $caps,
        ]);
    }
}
