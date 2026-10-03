<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('prompts') && ! $schema->hasColumn('prompts', 'is_flow_prompt')) {
            $schema->table('prompts', function (Blueprint $table): void {
                $table->boolean('is_flow_prompt')->default(false)->after('is_active');
            });
        }

        if ($schema->hasTable('seo_tasks') && ! $schema->hasColumn('seo_tasks', 'output_type')) {
            $schema->table('seo_tasks', function (Blueprint $table): void {
                $table->string('output_type', 16)->default('text')->after('flow_data');
            });
        }

        $this->backfillFlowMetadata();
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('seo_tasks') && $schema->hasColumn('seo_tasks', 'output_type')) {
            $schema->table('seo_tasks', function (Blueprint $table): void {
                $table->dropColumn('output_type');
            });
        }

        if ($schema->hasTable('prompts') && $schema->hasColumn('prompts', 'is_flow_prompt')) {
            $schema->table('prompts', function (Blueprint $table): void {
                $table->dropColumn('is_flow_prompt');
            });
        }
    }

    private function backfillFlowMetadata(): void
    {
        $db = DB::connection($this->connection);
        if (! Schema::connection($this->connection)->hasTable('seo_tasks')) {
            return;
        }

        $db->table('seo_tasks')->select(['id', 'flow_data'])->orderBy('id')->chunkById(100, function ($tasks) use ($db): void {
            foreach ($tasks as $task) {
                $flow = is_array($task->flow_data ?? null)
                    ? $task->flow_data
                    : json_decode((string) ($task->flow_data ?? ''), true);
                $nodes = is_array($flow) && is_array($flow['nodes'] ?? null) ? $flow['nodes'] : [];
                $ids = [];
                foreach ($nodes as $node) {
                    $data = is_array($node) && is_array($node['data'] ?? null) ? $node['data'] : [];
                    $id = (int) ($data['promptId'] ?? $data['prompt_id'] ?? 0);
                    if ($id > 0) {
                        $ids[$id] = $id;
                    }
                }

                if ($ids === []) {
                    continue;
                }

                $db->table('prompts')->whereIn('id', array_values($ids))->update(['is_flow_prompt' => true]);
                $tools = $db->table('prompts')->whereIn('id', array_values($ids))->pluck('tools');
                $outputType = 'text';
                foreach ($tools as $tool) {
                    $tool = strtolower(trim((string) $tool));
                    if ($tool === 'video') {
                        $outputType = 'video';
                        break;
                    }
                    if (in_array($tool, ['image', 'image_typography'], true)) {
                        $outputType = 'image';
                    }
                }
                $db->table('seo_tasks')->where('id', (int) $task->id)->update(['output_type' => $outputType]);
            }
        });
    }
};
