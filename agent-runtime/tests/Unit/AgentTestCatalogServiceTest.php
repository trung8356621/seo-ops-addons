<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AgentRuntime\Catalog\AgentTestCatalogService;
use Tests\TestCase;

final class AgentTestCatalogServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (array_unique([(string) config('database.default'), 'omi_seo_ai']) as $connection) {
            $schema = Schema::connection($connection);
            $schema->dropIfExists('seo_tasks');
            $schema->dropIfExists('prompts');
            $schema->create('prompts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('name');
                $table->string('tools')->default('default');
                $table->boolean('is_active')->default(true);
                $table->boolean('is_flow_prompt')->default(false);
                $table->softDeletes();
            });
            $schema->create('seo_tasks', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('name');
                $table->string('output_type')->default('text');
                $table->json('flow_data')->nullable();
                $table->boolean('is_active')->default(true);
            });

            DB::connection($connection)->table('prompts')->insert([
                ['id' => 1, 'user_id' => 7, 'name' => 'Text prompt', 'tools' => 'default', 'is_active' => true, 'is_flow_prompt' => false],
                ['id' => 2, 'user_id' => 7, 'name' => 'Image prompt', 'tools' => 'image_typography', 'is_active' => true, 'is_flow_prompt' => false],
                ['id' => 3, 'user_id' => 7, 'name' => 'Video prompt', 'tools' => 'video', 'is_active' => true, 'is_flow_prompt' => false],
                ['id' => 4, 'user_id' => 7, 'name' => 'Flow child', 'tools' => 'image', 'is_active' => true, 'is_flow_prompt' => true],
                ['id' => 5, 'user_id' => 8, 'name' => 'Other account', 'tools' => 'default', 'is_active' => true, 'is_flow_prompt' => false],
                ['id' => 6, 'user_id' => 7, 'name' => 'Inactive prompt', 'tools' => 'default', 'is_active' => false, 'is_flow_prompt' => false],
            ]);
            DB::connection($connection)->table('seo_tasks')->insert([
                ['id' => 10, 'user_id' => 7, 'name' => 'Product Gallery', 'output_type' => 'image', 'flow_data' => json_encode(['large' => 'graph'])],
                ['id' => 11, 'user_id' => 7, 'name' => 'task.video', 'output_type' => 'video', 'flow_data' => json_encode(['large' => 'graph'])],
                ['id' => 12, 'user_id' => 7, 'name' => 'Task Existing', 'output_type' => 'text', 'flow_data' => null],
                ['id' => 13, 'user_id' => 8, 'name' => 'Other task', 'output_type' => 'text', 'flow_data' => null],
            ]);
        }
    }

    public function test_catalog_filters_flow_only_and_account_rows_and_maps_media_types(): void
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $items = (new AgentTestCatalogService())->forOwner(7);

        self::assertSame([
            ['type' => 'prompt', 'id' => 2, 'label' => 'Image prompt', 'output_type' => 'image'],
            ['type' => 'prompt', 'id' => 1, 'label' => 'Text prompt', 'output_type' => 'text'],
            ['type' => 'prompt', 'id' => 3, 'label' => 'Video prompt', 'output_type' => 'video'],
            ['type' => 'task', 'id' => 10, 'label' => 'Task · Product Gallery', 'output_type' => 'image'],
            ['type' => 'task', 'id' => 12, 'label' => 'Task Existing', 'output_type' => 'text'],
            ['type' => 'task', 'id' => 11, 'label' => 'task.video', 'output_type' => 'video'],
        ], $items);

        $queries = $connection->getQueryLog();
        self::assertCount(2, $queries);
        self::assertStringNotContainsString('flow_data', implode(' ', array_column($queries, 'query')));
        self::assertStringNotContainsString('prompt_results', implode(' ', array_column($queries, 'query')));
    }
}
