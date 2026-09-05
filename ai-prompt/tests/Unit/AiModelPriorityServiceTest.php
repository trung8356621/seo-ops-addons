<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Models\AiModelCapabilityRow;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use Omnichannel\Addons\AiPrompt\Services\ModelCapabilityRegistry;
use Omnichannel\Addons\AiPrompt\Support\AiCapabilitySource;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Omnichannel\Addons\AiPrompt\Support\AiUsageMode;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use Tests\TestCase;

final class AiModelPriorityServiceTest extends TestCase
{
    private AiModelPriorityService $priorities;

    private AiRoutingTargetService $targets;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['ai_routing_targets', 'ai_routing_profiles', 'ai_model_capabilities', 'seo_ai_models', 'api_connections'] as $table) {
            Schema::dropIfExists($table);
        }
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
        $this->priorities = new AiModelPriorityService();
        $this->targets = new AiRoutingTargetService(new ModelCapabilityRegistry());
    }

    public function test_provider_reorder_is_persisted(): void
    {
        $deepseek = $this->connection(1, ApiConnectionProviders::DEEPSEEK, 'DeepSeek');
        $gemini = $this->connection(1, ApiConnectionProviders::GEMINI, 'Gemini');
        $openrouter = $this->connection(1, ApiConnectionProviders::OPENROUTER, 'OpenRouter');
        $this->priorities->reorderProviders(1, [(int) $deepseek->id, (int) $gemini->id, (int) $openrouter->id]);
        $this->priorities->reorderProviders(1, [(int) $openrouter->id, (int) $deepseek->id, (int) $gemini->id]);
        $ordered = $this->priorities->aiConnections(1);
        $this->assertSame([(int) $openrouter->id, (int) $deepseek->id, (int) $gemini->id], array_map(
            static fn (ApiConnection $row): int => (int) $row->id,
            $ordered,
        ));
        $openrouter->refresh();
        $this->assertSame(10, (int) ($openrouter->metadata['routing_priority'] ?? 0));
    }

    public function test_model_reorder_changes_automatic_sequence(): void
    {
        $deepseek = $this->connection(2, ApiConnectionProviders::DEEPSEEK, 'DeepSeek');
        $chat = $this->model($deepseek, 'deepseek-chat', AiModelCategory::DEEPSEEK_CHAT, 10);
        $reasoner = $this->model($deepseek, 'deepseek-reasoner', AiModelCategory::DEEPSEEK_REASONER, 20);
        $this->grantText($deepseek, $chat);
        $this->grantText($deepseek, $reasoner);
        $this->priorities->reorderModels(2, (int) $deepseek->id, [(int) $reasoner->id, (int) $chat->id]);
        $resolved = $this->targets->eligibleCandidates(2, AiExecutionProfile::TextFast, new AiRoutingContext(userId: 2));
        $this->assertSame(['deepseek-reasoner', 'deepseek-chat'], array_map(
            static fn ($candidate): string => $candidate->model,
            $resolved,
        ));
    }

    public function test_automatic_follows_table_order_and_filters_incompatible(): void
    {
        $deepseek = $this->connection(3, ApiConnectionProviders::DEEPSEEK, 'DeepSeek');
        $gemini = $this->connection(3, ApiConnectionProviders::GEMINI, 'Gemini');
        $this->priorities->reorderProviders(3, [(int) $deepseek->id, (int) $gemini->id]);
        $this->model($deepseek, 'deepseek-chat', AiModelCategory::DEEPSEEK_CHAT, 10);
        $this->model($deepseek, 'deepseek-reasoner', AiModelCategory::DEEPSEEK_REASONER, 20);
        $flash = $this->model($gemini, 'gemini-3-flash-preview', AiModelCategory::GEMINI_FLASH, 10);
        $this->grantText($gemini, $flash);
        $resolved = $this->targets->eligibleCandidates(
            3,
            AiExecutionProfile::TextReasoning,
            new AiRoutingContext(userId: 3, hookKey: 'article.outline.structure.generate'),
        );
        $models = array_map(static fn ($candidate): string => $candidate->model, $resolved);
        // Production: DeepSeek is never eligible for Outline/Vocabulary TextReasoning.
        $this->assertNotContains('deepseek-chat', $models);
        $this->assertNotContains('deepseek-reasoner', $models);
        $this->assertSame(['gemini-3-flash-preview'], $models);
    }

    public function test_custom_selection_does_not_filter_text_runtime_area_order(): void
    {
        $deepseek = $this->connection(4, ApiConnectionProviders::DEEPSEEK, 'DeepSeek');
        $gemini = $this->connection(4, ApiConnectionProviders::GEMINI, 'Gemini');
        $this->priorities->reorderProviders(4, [(int) $deepseek->id, (int) $gemini->id]);
        $chat = $this->model($deepseek, 'deepseek-chat', AiModelCategory::DEEPSEEK_CHAT, 10);
        $reasoner = $this->model($deepseek, 'deepseek-reasoner', AiModelCategory::DEEPSEEK_REASONER, 20);
        $flash = $this->model($gemini, 'gemini-3-flash-preview', AiModelCategory::GEMINI_FLASH, 10);
        $pro = $this->model($gemini, 'gemini-3.1-pro-preview', AiModelCategory::GEMINI_PRO, 20);
        $this->grantText($deepseek, $chat);
        $this->grantText($deepseek, $reasoner);
        $this->grantText($gemini, $flash);
        $this->grantText($gemini, $pro);
        $this->targets->saveSimplifiedSelection(
            4,
            AiExecutionProfile::TextFast,
            [(string) $gemini->id.'|gemini.pro', (string) $deepseek->id.'|deepseek.chat'],
            AiUsageMode::Economy,
            true,
            true,
        );
        // Text runtime SSOT = capability-area order; Custom membership is not a runtime filter.
        $resolved = $this->targets->eligibleCandidates(4, AiExecutionProfile::TextFast, new AiRoutingContext(userId: 4));
        $this->assertSame([
            'deepseek-chat',
            'deepseek-reasoner',
            'gemini-3-flash-preview',
            'gemini-3.1-pro-preview',
        ], array_map(
            static fn ($candidate): string => $candidate->model,
            $resolved,
        ));
    }

    public function test_new_model_appends_and_economy_does_not_reorder(): void
    {
        $gemini = $this->connection(5, ApiConnectionProviders::GEMINI, 'Gemini');
        $flash = $this->model($gemini, 'gemini-3-flash-preview', AiModelCategory::GEMINI_FLASH, 10);
        $pro = $this->model($gemini, 'gemini-3.1-pro-preview', AiModelCategory::GEMINI_PRO, 20, true);
        $this->grantText($gemini, $flash);
        $this->grantText($gemini, $pro);
        $this->priorities->appendEnabled(5, [(int) $pro->id]);
        $pro->refresh();
        $this->assertGreaterThan((int) $flash->priority, (int) $pro->priority);
        $this->targets->writeProfileSettings(5, AiExecutionProfile::TextFast, [
            'usage_mode' => 'economy',
            'allowed_execution_keys' => [],
        ]);
        $pro->is_hidden = false;
        $pro->save();
        $resolved = $this->targets->eligibleCandidates(5, AiExecutionProfile::TextFast, new AiRoutingContext(userId: 5));
        $this->assertSame(['gemini-3-flash-preview', 'gemini-3.1-pro-preview'], array_map(
            static fn ($candidate): string => $candidate->model,
            $resolved,
        ));
    }

    public function test_new_provider_defaults_to_bottom(): void
    {
        $deepseek = $this->connection(6, ApiConnectionProviders::DEEPSEEK, 'DeepSeek');
        $gemini = $this->connection(6, ApiConnectionProviders::GEMINI, 'Gemini');
        $this->priorities->reorderProviders(6, [(int) $deepseek->id, (int) $gemini->id]);
        $openrouter = $this->connection(6, ApiConnectionProviders::OPENROUTER, 'OpenRouter');
        $this->priorities->assignBottomProviderPriority(6, $openrouter);
        $ordered = array_map(static fn (ApiConnection $row): int => (int) $row->id, $this->priorities->aiConnections(6));
        $this->assertSame([(int) $deepseek->id, (int) $gemini->id, (int) $openrouter->id], $ordered);
    }

    public function test_same_family_on_two_connections_keeps_independent_priority(): void
    {
        $gemini = $this->connection(7, ApiConnectionProviders::GEMINI, 'Gemini');
        $openrouter = $this->connection(7, ApiConnectionProviders::OPENROUTER, 'OpenRouter');
        $this->priorities->reorderProviders(7, [(int) $openrouter->id, (int) $gemini->id]);
        $native = $this->model($gemini, 'gemini-3-flash-preview', AiModelCategory::GEMINI_FLASH, 50);
        $viaRouter = $this->model($openrouter, 'google/gemini-3-flash-preview', AiModelCategory::GEMINI_FLASH, 10);
        $this->grantText($gemini, $native);
        $this->grantText($openrouter, $viaRouter);
        $resolved = $this->targets->eligibleCandidates(7, AiExecutionProfile::TextFast, new AiRoutingContext(userId: 7));
        $this->assertSame(['google/gemini-3-flash-preview', 'gemini-3-flash-preview'], array_map(
            static fn ($candidate): string => $candidate->model,
            $resolved,
        ));
        $this->priorities->reorderModels(7, (int) $gemini->id, [(int) $native->id]);
        $native->refresh();
        $viaRouter->refresh();
        $this->assertSame(10, (int) $native->priority);
        $this->assertSame(10, (int) $viaRouter->priority);
    }

    public function test_text_area_order_filters_incompatible_and_ignores_custom_click_order(): void
    {
        $deepseek = $this->connection(8, ApiConnectionProviders::DEEPSEEK, 'DeepSeek');
        $gemini = $this->connection(8, ApiConnectionProviders::GEMINI, 'Gemini');
        $chat = $this->model($deepseek, 'deepseek-chat', AiModelCategory::DEEPSEEK_CHAT, 10);
        $reasoner = $this->model($deepseek, 'deepseek-reasoner', AiModelCategory::DEEPSEEK_REASONER, 20);
        $flash = $this->model($gemini, 'gemini-3-flash-preview', AiModelCategory::GEMINI_FLASH, 10);
        $pro = $this->model($gemini, 'gemini-3.1-pro-preview', AiModelCategory::GEMINI_PRO, 20);
        $this->grantText($deepseek, $chat, [AiModelCapability::TextGenerate->value]);
        $this->grantText($deepseek, $reasoner);
        $this->grantText($gemini, $flash, [AiModelCapability::TextGenerate->value]);
        $this->grantText($gemini, $pro);
        $this->priorities->reorderArea(8, AiModelArea::Text, [
            (int) $chat->id,
            (int) $reasoner->id,
            (int) $flash->id,
            (int) $pro->id,
        ]);
        $resolved = $this->targets->eligibleCandidates(
            8,
            AiExecutionProfile::TextReasoning,
            new AiRoutingContext(userId: 8, hookKey: 'article.outline.structure.generate'),
        );
        $reasoningModels = array_map(static fn ($c): string => $c->model, $resolved);
        $this->assertNotContains('deepseek-chat', $reasoningModels);
        $this->assertNotContains('deepseek-reasoner', $reasoningModels);
        $this->targets->saveSimplifiedSelection(
            8,
            AiExecutionProfile::TextFast,
            [(string) $gemini->id.'|gemini.pro', (string) $deepseek->id.'|deepseek.chat'],
            AiUsageMode::Economy,
            true,
            true,
        );
        $custom = $this->targets->eligibleCandidates(8, AiExecutionProfile::TextFast, new AiRoutingContext(userId: 8));
        // Legacy Text area enable + capability filter; Custom click order is not runtime SSOT.
        $this->assertSame([
            'deepseek-chat',
            'deepseek-reasoner',
            'gemini-3-flash-preview',
            'gemini-3.1-pro-preview',
        ], array_map(
            static fn ($candidate): string => $candidate->model,
            $custom,
        ));
    }

    public function test_text_reorder_does_not_change_image_area_priority(): void
    {
        $gemini = $this->connection(9, ApiConnectionProviders::GEMINI, 'Gemini');
        $flash = $this->model($gemini, 'gemini-3-flash-preview', AiModelCategory::GEMINI_FLASH, 10);
        $nano = $this->model($gemini, 'gemini-3.1-flash-image-preview', 'imagen_pro', 20);
        $imagen = $this->model($gemini, 'imagen-4.0-fast-generate-001', 'imagen_pro', 30);
        $this->grantText($gemini, $flash);
        $this->priorities->reorderArea(9, AiModelArea::Image, [(int) $imagen->id, (int) $nano->id]);
        $before = $this->priorities->areaPriority($imagen->fresh(), AiModelArea::Image, $gemini);
        $this->priorities->reorderArea(9, AiModelArea::Text, [(int) $flash->id]);
        $after = $this->priorities->areaPriority($imagen->fresh(), AiModelArea::Image, $gemini);
        $this->assertSame($before, $after);
        $this->priorities->appendToArea(9, AiModelArea::Text, [(int) $flash->id]);
        $this->assertFalse($this->priorities->isAreaEnabled($nano->fresh(), AiModelArea::Text, $gemini));
    }

    public function test_capability_reorder_rejects_foreign_id_and_persists_permutation(): void
    {
        $deepseek = $this->connection(10, ApiConnectionProviders::DEEPSEEK, 'DeepSeek');
        $gemini = $this->connection(10, ApiConnectionProviders::GEMINI, 'Gemini');
        $chat = $this->model($deepseek, 'deepseek-chat', AiModelCategory::DEEPSEEK_CHAT, 10);
        $flash = $this->model($gemini, 'gemini-3-flash-preview', AiModelCategory::GEMINI_FLASH, 20);
        $this->grantText($deepseek, $chat, [AiModelCapability::TextGenerate->value]);
        $this->grantText($gemini, $flash, [AiModelCapability::TextGenerate->value]);
        $this->expectException(\InvalidArgumentException::class);
        $this->priorities->reorderCapabilityModels(10, AiModelArea::Text, [(int) $flash->id, 999999]);
    }

    public function test_capability_reorder_updates_automatic_sequence(): void
    {
        $deepseek = $this->connection(11, ApiConnectionProviders::DEEPSEEK, 'DeepSeek');
        $gemini = $this->connection(11, ApiConnectionProviders::GEMINI, 'Gemini');
        $chat = $this->model($deepseek, 'deepseek-chat', AiModelCategory::DEEPSEEK_CHAT, 10);
        $flash = $this->model($gemini, 'gemini-3-flash-preview', AiModelCategory::GEMINI_FLASH, 20);
        $this->grantText($deepseek, $chat, [AiModelCapability::TextGenerate->value]);
        $this->grantText($gemini, $flash, [AiModelCapability::TextGenerate->value]);
        $this->priorities->reorderCapabilityModels(11, AiModelArea::Text, [(int) $flash->id, (int) $chat->id]);
        $resolved = $this->targets->eligibleCandidates(11, AiExecutionProfile::TextFast, new AiRoutingContext(userId: 11));
        $this->assertSame(['gemini-3-flash-preview', 'deepseek-chat'], array_map(
            static fn ($candidate): string => $candidate->model,
            $resolved,
        ));
        $flash->refresh();
        $this->assertSame(1, (int) ($flash->capabilities['omi_areas']['text']['priority'] ?? 0));
    }

    private function connection(int $userId, string $provider, string $name): ApiConnection
    {
        return ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => $provider,
            'name' => $name,
            'api_key' => 'k',
            'status' => 'active',
            'is_global' => false,
            'metadata' => [],
        ]);
    }

    private function model(ApiConnection $connection, string $raw, string $category, int $priority, bool $hidden = false): SeoAiModel
    {
        return SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'category' => $category,
            'raw_model_name' => $raw,
            'display_name' => $raw,
            'priority' => $priority,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'is_hidden' => $hidden,
        ]);
    }

    private function grantText(ApiConnection $connection, SeoAiModel $model, ?array $capabilities = null): void
    {
        $capabilities ??= [
            AiModelCapability::TextGenerate->value,
            AiModelCapability::TextReasoning->value,
        ];
        foreach ($capabilities as $capability) {
            AiModelCapabilityRow::query()->create([
                'api_connection_id' => $connection->id,
                'seo_ai_model_id' => $model->id,
                'model_key' => (string) $model->raw_model_name,
                'capability' => $capability,
                'source' => AiCapabilitySource::Manual->value,
                'enabled' => true,
            ]);
        }
    }
}
