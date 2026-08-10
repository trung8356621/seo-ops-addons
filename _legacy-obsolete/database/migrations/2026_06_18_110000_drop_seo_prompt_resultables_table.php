<?php

declare(strict_types=1);

use Omnichannel\Addons\Content\Models\SeoArticle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_prompt_resultables')) {
            return;
        }

        $this->migratePivotRowsToPromptResultLinks();

        Schema::connection($this->connection)->dropIfExists('seo_prompt_resultables');
    }

    public function down(): void
    {
        Schema::connection($this->connection)->create('seo_prompt_resultables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prompt_result_id')
                ->constrained('prompt_results')
                ->cascadeOnDelete();
            $table->string('prompt_resultable_type');
            $table->unsignedBigInteger('prompt_resultable_id');
            $table->index(
                ['prompt_resultable_type', 'prompt_resultable_id'],
                'seo_pr_resultable_morph_idx',
            );
            $table->string('type', 50);
            $table->index('type', 'seo_pr_resultable_type_idx');
            $table->timestamps();

            $table->unique(
                ['prompt_resultable_type', 'prompt_resultable_id', 'type'],
                'seo_pr_entity_type_unique',
            );
        });
    }

    private function migratePivotRowsToPromptResultLinks(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_prompt_result_links')) {
            return;
        }

        DB::connection($this->connection)
            ->table('seo_prompt_resultables')
            ->where('prompt_resultable_type', SeoArticle::class)
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $promptResultId = (int) ($row->prompt_result_id ?? 0);
                    $articleId = (int) ($row->prompt_resultable_id ?? 0);

                    if ($promptResultId <= 0 || $articleId <= 0) {
                        continue;
                    }

                    $meta = json_encode([
                        'legacy_type' => (string) ($row->type ?? ''),
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

                    DB::connection($this->connection)->table('seo_prompt_result_links')->updateOrInsert(
                        [
                            'prompt_result_id' => $promptResultId,
                            'source' => 'legacy_pivot',
                            'project_run_id' => null,
                            'project_task_id' => null,
                            'workflow_node_id' => null,
                        ],
                        [
                            'article_id' => $articleId,
                            'user_id' => null,
                            'workflow_step_title' => null,
                            'meta' => $meta,
                            'created_at' => $row->created_at ?? now(),
                            'updated_at' => now(),
                        ],
                    );
                }
            });
    }
};
