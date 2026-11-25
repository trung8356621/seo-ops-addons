<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Models\AiModelCapabilityRow;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiCenterModelPresenter;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use Tests\TestCase;

final class PerConnectionModelSortableTest extends TestCase
{
    private AiModelPriorityService $priorities;

    private AiCenterModelPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('ai_model_capabilities');
        Schema::dropIfExists('seo_ai_models');
        Schema::dropIfExists('api_connections');
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
            $table->string('display_name')->nullable();
            $table->unsignedInteger('priority')->default(100);
            $table->string('status')->default('active');
            $table->boolean('is_hidden')->default(false);
            $table->json('capabilities')->nullable();
            $table->timestamps();
        });
        Schema::create('ai_model_capabilities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('api_connection_id')->nullable();
            $table->unsignedBigInteger('seo_ai_model_id')->nullable();
            $table->string('model_key');
            $table->string('capability');
            $table->string('source')->default('built_in');
            $table->boolean('enabled')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        $this->priorities = new AiModelPriorityService();
        $this->presenter = new AiCenterModelPresenter(priorities: $this->priorities);
    }

    public function test_area_rows_filter_to_selected_connection_only(): void
    {
        $or = $this->connection(21, ApiConnectionProviders::OPENROUTER, 'new-ops');
        $groq = $this->connection(21, 'groqcloud', 'GroqCloud');
        $orModel = $this->model($or, 'qwen/qwen3-32b', AiModelCategory::GEMINI_FLASH, 10);
        $groqModel = $this->model($groq, 'llama-3.3-70b', AiModelCategory::GEMINI_FLASH, 10);
        $this->grant($or, $orModel);
        $this->grant($groq, $groqModel);
        $this->priorities->appendToArea(21, AiModelArea::TextFast, [(int) $orModel->id, (int) $groqModel->id]);

        $orRows = $this->presenter->areaRows(21, AiModelArea::TextFast, ['connection_id' => (int) $or->id]);
        $groqRows = $this->presenter->areaRows(21, AiModelArea::TextFast, ['connection_id' => (int) $groq->id]);

        $this->assertSame([(int) $or->id], array_values(array_unique(array_map(
            static fn (array $row): int => (int) ($row['connection_id'] ?? 0),
            $orRows,
        ))));
        $this->assertSame([(int) $groq->id], array_values(array_unique(array_map(
            static fn (array $row): int => (int) ($row['connection_id'] ?? 0),
            $groqRows,
        ))));
        $this->assertFalse(collect($orRows)->contains(fn (array $row): bool => str_contains((string) ($row['label'] ?? ''), 'llama')));
        $this->assertFalse(collect($groqRows)->contains(fn (array $row): bool => str_contains((string) ($row['label'] ?? ''), 'qwen')));
    }

    public function test_reorder_one_connection_does_not_change_another(): void
    {
        $or = $this->connection(22, ApiConnectionProviders::OPENROUTER, 'OR');
        $groq = $this->connection(22, 'groqcloud', 'Groq');
        $qwen = $this->model($or, 'qwen/qwen3-32b', AiModelCategory::GEMINI_FLASH, 10);
        $gpt = $this->model($or, 'openai/gpt-5-nano', AiModelCategory::GEMINI_FLASH, 20);
        $llama = $this->model($groq, 'llama-3.3-70b', AiModelCategory::GEMINI_FLASH, 10);
        $oss = $this->model($groq, 'openai/gpt-oss', AiModelCategory::GEMINI_FLASH, 20);
        foreach ([$qwen, $gpt, $llama, $oss] as $model) {
            $this->grant($model->apiConnection, $model);
        }
        $this->priorities->appendToArea(22, AiModelArea::TextFast, [(int) $qwen->id, (int) $gpt->id, (int) $llama->id, (int) $oss->id]);
        $this->priorities->reorderCapabilityModels(22, AiModelArea::TextFast, [(int) $qwen->id, (int) $gpt->id], (int) $or->id);
        $this->priorities->reorderCapabilityModels(22, AiModelArea::TextFast, [(int) $llama->id, (int) $oss->id], (int) $groq->id);

        $beforeGroq = [
            $this->priorities->physicalAreaPriority($llama->fresh(), AiModelArea::TextFast),
            $this->priorities->physicalAreaPriority($oss->fresh(), AiModelArea::TextFast),
        ];
        $this->priorities->reorderCapabilityModels(22, AiModelArea::TextFast, [(int) $gpt->id, (int) $qwen->id], (int) $or->id);
        $afterGroq = [
            $this->priorities->physicalAreaPriority($llama->fresh(), AiModelArea::TextFast),
            $this->priorities->physicalAreaPriority($oss->fresh(), AiModelArea::TextFast),
        ];
        $this->assertSame($beforeGroq, $afterGroq);
        $this->assertSame(1, $this->priorities->physicalAreaPriority($gpt->fresh(), AiModelArea::TextFast));
        $this->assertSame(2, $this->priorities->physicalAreaPriority($qwen->fresh(), AiModelArea::TextFast));
    }

    public function test_reorder_fast_text_does_not_change_longform(): void
    {
        $or = $this->connection(23, ApiConnectionProviders::OPENROUTER, 'OR');
        $a = $this->model($or, 'qwen/qwen3-32b', AiModelCategory::GEMINI_FLASH, 10);
        $b = $this->model($or, 'openai/gpt-5-nano', AiModelCategory::GEMINI_FLASH, 20);
        $this->grant($or, $a);
        $this->grant($or, $b);
        $this->priorities->appendToArea(23, AiModelArea::TextFast, [(int) $a->id, (int) $b->id]);
        $this->priorities->appendToArea(23, AiModelArea::TextLongform, [(int) $a->id, (int) $b->id]);
        $this->priorities->reorderCapabilityModels(23, AiModelArea::TextLongform, [(int) $a->id, (int) $b->id], (int) $or->id);
        $before = [
            $this->priorities->physicalAreaPriority($a->fresh(), AiModelArea::TextLongform),
            $this->priorities->physicalAreaPriority($b->fresh(), AiModelArea::TextLongform),
        ];
        $this->priorities->reorderCapabilityModels(23, AiModelArea::TextFast, [(int) $b->id, (int) $a->id], (int) $or->id);
        $after = [
            $this->priorities->physicalAreaPriority($a->fresh(), AiModelArea::TextLongform),
            $this->priorities->physicalAreaPriority($b->fresh(), AiModelArea::TextLongform),
        ];
        $this->assertSame($before, $after);
    }

    public function test_inactive_connection_order_preserved_across_disable_enable(): void
    {
        $or = $this->connection(24, ApiConnectionProviders::OPENROUTER, 'OR');
        $a = $this->model($or, 'qwen/qwen3-32b', AiModelCategory::GEMINI_FLASH, 10);
        $b = $this->model($or, 'openai/gpt-5-nano', AiModelCategory::GEMINI_FLASH, 20);
        $this->grant($or, $a);
        $this->grant($or, $b);
        $this->priorities->appendToArea(24, AiModelArea::TextFast, [(int) $a->id, (int) $b->id]);
        $this->priorities->reorderCapabilityModels(24, AiModelArea::TextFast, [(int) $b->id, (int) $a->id], (int) $or->id);
        $before = [
            $this->priorities->physicalAreaPriority($b->fresh(), AiModelArea::TextFast),
            $this->priorities->physicalAreaPriority($a->fresh(), AiModelArea::TextFast),
        ];
        $or->status = 'inactive';
        $or->save();
        $rows = $this->presenter->areaRows(24, AiModelArea::TextFast, ['connection_id' => (int) $or->id]);
        $this->assertNotEmpty($rows);
        $or->status = 'active';
        $or->save();
        $after = [
            $this->priorities->physicalAreaPriority($b->fresh(), AiModelArea::TextFast),
            $this->priorities->physicalAreaPriority($a->fresh(), AiModelArea::TextFast),
        ];
        $this->assertSame($before, $after);
    }

    public function test_runtime_falls_back_by_connection_then_model_order(): void
    {
        $or = $this->connection(25, ApiConnectionProviders::OPENROUTER, 'OR');
        $ds = $this->connection(25, ApiConnectionProviders::DEEPSEEK, 'DeepSeek');
        $or->metadata = ['routing_priority' => 10];
        $or->save();
        $ds->metadata = ['routing_priority' => 20];
        $ds->save();
        $orA = $this->model($or, 'qwen/qwen3-32b', AiModelCategory::GEMINI_FLASH, 10);
        $orB = $this->model($or, 'openai/gpt-5-nano', AiModelCategory::GEMINI_FLASH, 20);
        $dsA = $this->model($ds, 'deepseek-chat', AiModelCategory::DEEPSEEK_CHAT, 10);
        foreach ([$orA, $orB, $dsA] as $model) {
            $this->grant($model->apiConnection, $model);
        }
        $this->priorities->appendToArea(25, AiModelArea::TextFast, [(int) $orA->id, (int) $orB->id, (int) $dsA->id]);
        $this->priorities->reorderCapabilityModels(25, AiModelArea::TextFast, [(int) $orB->id, (int) $orA->id], (int) $or->id);
        $this->priorities->reorderCapabilityModels(25, AiModelArea::TextFast, [(int) $dsA->id], (int) $ds->id);

        $ordered = $this->priorities->effectiveAreaModels(25, AiModelArea::TextFast);
        $this->assertSame(
            ['openai/gpt-5-nano', 'qwen/qwen3-32b', 'deepseek-chat'],
            array_map(static fn (SeoAiModel $model): string => (string) $model->raw_model_name, $ordered),
        );
        $this->assertSame(
            [(int) $or->id, (int) $or->id, (int) $ds->id],
            array_map(static fn (SeoAiModel $model): int => (int) $model->api_connection_id, $ordered),
        );
    }

    public function test_page_source_has_connection_selector_not_grouped_blocks(): void
    {
        $page = (string) file_get_contents(
            (new \ReflectionClass(\Omnichannel\Addons\AiPrompt\Filament\Pages\SeoSettingsAiCenter::class))->getFileName(),
        );
        $viewPath = dirname(__DIR__, 3).DIRECTORY_SEPARATOR
            .'seo-content-ai-compat'.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR
            .'views'.DIRECTORY_SEPARATOR.'filament'.DIRECTORY_SEPARATOR.'pages'
            .DIRECTORY_SEPARATOR.'seo-settings-ai-center.blade.php';
        $this->assertFileExists($viewPath);
        $view = (string) file_get_contents($viewPath);
        $this->assertStringContainsString('modelsConnectionId', $page);
        $this->assertStringContainsString('wire:model.live="modelsConnectionId"', $view);
        $this->assertStringContainsString('models_connection_label', $view);
        $this->assertStringNotContainsString('seo-ai-models-table--grouped', $view);
        $this->assertStringNotContainsString('seo-ai-connection-block', $view);
    }

    private function connection(int $userId, string $provider, string $name): ApiConnection
    {
        return ApiConnection::query()->create([
            'user_id' => $userId,
            'provider' => $provider,
            'name' => $name,
            'api_key' => 'sk-test-key-long-enough',
            'status' => 'active',
            'is_global' => false,
            'metadata' => [],
        ]);
    }

    private function model(ApiConnection $connection, string $raw, string $category, int $priority): SeoAiModel
    {
        return SeoAiModel::query()->create([
            'api_connection_id' => $connection->id,
            'category' => $category,
            'raw_model_name' => $raw,
            'display_name' => $raw,
            'priority' => $priority,
            'status' => SeoAiModel::STATUS_ACTIVE,
            'is_hidden' => false,
            'capabilities' => [],
        ]);
    }

    private function grant(ApiConnection $connection, SeoAiModel $model): void
    {
        foreach ([AiModelCapability::TextGenerate->value, AiModelCapability::TextReasoning->value] as $capability) {
            AiModelCapabilityRow::query()->create([
                'api_connection_id' => $connection->id,
                'seo_ai_model_id' => $model->id,
                'model_key' => (string) $model->raw_model_name,
                'capability' => $capability,
                'source' => 'built_in',
                'enabled' => true,
            ]);
        }
    }
}
