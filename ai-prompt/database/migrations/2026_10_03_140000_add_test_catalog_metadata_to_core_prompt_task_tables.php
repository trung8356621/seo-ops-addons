<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mysql';

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

        if ($schema->hasTable('prompts') && $schema->hasColumn('prompts', 'is_flow_prompt')) {
            DB::connection($this->connection)->table('prompts')->whereIn('hook_key', [
                'product.gallery.parent.generate',
                'product.gallery.child.generate',
            ])->update(['is_flow_prompt' => true]);
        }

        $this->backfillTaskOutputTypes();
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if ($schema->hasTable('seo_tasks') && $schema->hasColumn('seo_tasks', 'output_type')) {
            $schema->table('seo_tasks', fn (Blueprint $table) => $table->dropColumn('output_type'));
        }
        if ($schema->hasTable('prompts') && $schema->hasColumn('prompts', 'is_flow_prompt')) {
            $schema->table('prompts', fn (Blueprint $table) => $table->dropColumn('is_flow_prompt'));
        }
    }

    private function backfillTaskOutputTypes(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_tasks') || ! $schema->hasTable('prompts')) {
            return;
        }

        $db = DB::connection($this->connection);
        $db->table('seo_tasks')->select(['id', 'flow_data'])->orderBy('id')->chunkById(100, function ($tasks) use ($db): void {
            foreach ($tasks as $task) {
                $flow = json_decode((string) ($task->flow_data ?? ''), true);
                $ids = [];
                foreach (is_array($flow['nodes'] ?? null) ? $flow['nodes'] : [] as $node) {
                    $data = is_array($node['data'] ?? null) ? $node['data'] : [];
                    $id = (int) ($data['promptId'] ?? $data['prompt_id'] ?? 0);
                    if ($id > 0) {
                        $ids[$id] = $id;
                    }
                }
                if ($ids === []) {
                    continue;
                }

                $outputType = 'text';
                foreach ($db->table('prompts')->whereIn('id', array_values($ids))->pluck('tools') as $tool) {
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
