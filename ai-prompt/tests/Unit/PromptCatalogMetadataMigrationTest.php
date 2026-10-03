<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\AiPrompt\Services\PromptVersionService;
use Omnichannel\Addons\AiPrompt\Services\TaskFlowPromptMetadataService;
use ReflectionClass;
use Tests\TestCase;

final class PromptCatalogMetadataMigrationTest extends TestCase
{
    public function test_migration_adds_columns_and_backfills_referenced_prompts_and_task_output(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        $schema->dropIfExists('seo_tasks');
        $schema->dropIfExists('prompts');
        $schema->create('prompts', function (Blueprint $table): void {
            $table->id();
            $table->string('tools')->default('default');
            $table->boolean('is_active')->default(true);
        });
        $schema->create('seo_tasks', function (Blueprint $table): void {
            $table->id();
            $table->json('flow_data')->nullable();
        });
        DB::connection('omi_seo_ai')->table('prompts')->insert([
            ['id' => 20, 'tools' => 'video'],
            ['id' => 21, 'tools' => 'default'],
        ]);
        DB::connection('omi_seo_ai')->table('seo_tasks')->insert([
            'id' => 1,
            'flow_data' => json_encode(['nodes' => [['data' => ['promptId' => 20]]]], JSON_THROW_ON_ERROR),
        ]);

        $migration = require dirname((new ReflectionClass(TaskFlowPromptMetadataService::class))->getFileName(), 3)
            .'/database/migrations/2026_10_03_120000_add_flow_prompt_and_task_output_metadata.php';
        $migration->up();

        self::assertTrue($schema->hasColumn('prompts', 'is_flow_prompt'));
        self::assertTrue($schema->hasColumn('seo_tasks', 'output_type'));
        self::assertSame(1, (int) DB::connection('omi_seo_ai')->table('prompts')->where('id', 20)->value('is_flow_prompt'));
        self::assertSame(0, (int) DB::connection('omi_seo_ai')->table('prompts')->where('id', 21)->value('is_flow_prompt'));
        self::assertSame('video', DB::connection('omi_seo_ai')->table('seo_tasks')->where('id', 1)->value('output_type'));
    }

    public function test_models_and_prompt_list_use_scalar_metadata_without_usage_aggregates(): void
    {
        $prompt = new SeoPrompt(['is_flow_prompt' => 1]);
        $task = new SeoTask(['output_type' => 'video']);
        self::assertTrue($prompt->is_flow_prompt);
        self::assertSame('video', $task->output_type);

        $resource = (string) file_get_contents((new ReflectionClass(PromptResource::class))->getFileName());
        self::assertStringNotContainsString("withCount('promptResults')", $resource);
        self::assertStringNotContainsString("withMax('promptResults'", $resource);
        self::assertStringNotContainsString("with(['aiConnection'", $resource);
        self::assertStringNotContainsString("TextColumn::make('usage')", $resource);
        self::assertStringNotContainsString("Action::make('test')", $resource);

        $versions = (string) file_get_contents((new ReflectionClass(PromptVersionService::class))->getFileName());
        self::assertStringNotContainsString("'is_flow_prompt'", $versions);
    }

    public function test_flow_metadata_service_marks_both_prompt_key_shapes_and_resolves_media_type(): void
    {
        foreach (array_unique([(string) config('database.default'), 'omi_seo_ai']) as $connection) {
            $schema = Schema::connection($connection);
            $schema->dropIfExists('prompts');
            $schema->create('prompts', function (Blueprint $table): void {
                $table->id();
                $table->string('tools')->default('default');
                $table->boolean('is_flow_prompt')->default(false);
                $table->timestamps();
                $table->softDeletes();
            });
            DB::connection($connection)->table('prompts')->insert([
                ['id' => 10, 'tools' => 'default', 'is_flow_prompt' => false],
                ['id' => 11, 'tools' => 'image', 'is_flow_prompt' => false],
            ]);
        }

        $flow = ['nodes' => [
            ['data' => ['promptId' => 10]],
            ['data' => ['prompt_id' => 11]],
        ]];
        $service = new TaskFlowPromptMetadataService();
        $service->markFlowPrompts($flow);

        self::assertSame('image', $service->outputType($flow));
        self::assertSame(2, SeoPrompt::query()->where('is_flow_prompt', true)->count());
    }
}
