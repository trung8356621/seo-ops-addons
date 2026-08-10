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
        Schema::connection($this->connection)->create('seo_prompt_resultables', function (Blueprint $table) {
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

        $this->migrateLegacyArticlePromptResultLinks();
        $this->dropLegacyForeignKeyColumns();
    }

    public function down(): void
    {
        $this->restoreLegacyForeignKeyColumns();
        $this->migratePivotBackToArticlePromptResultId();

        Schema::connection($this->connection)->dropIfExists('seo_prompt_resultables');
    }

    private function migrateLegacyArticlePromptResultLinks(): void
    {
        foreach ($this->articleTableNames() as $table) {
            if (! Schema::connection($this->connection)->hasColumn($table, 'prompt_result_id')) {
                continue;
            }

            $rows = DB::connection($this->connection)
                ->table($table)
                ->whereNotNull('prompt_result_id')
                ->get(['id', 'prompt_result_id']);

            foreach ($rows as $row) {
                DB::connection($this->connection)->table('seo_prompt_resultables')->updateOrInsert(
                    [
                        'prompt_resultable_type' => SeoArticle::class,
                        'prompt_resultable_id' => $row->id,
                        'type' => 'content',
                    ],
                    [
                        'prompt_result_id' => $row->prompt_result_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }
    }

    private function migratePivotBackToArticlePromptResultId(): void
    {
        foreach ($this->articleTableNames() as $table) {
            if (! Schema::connection($this->connection)->hasColumn($table, 'prompt_result_id')) {
                continue;
            }

            $links = DB::connection($this->connection)
                ->table('seo_prompt_resultables')
                ->where('prompt_resultable_type', SeoArticle::class)
                ->orderByDesc('id')
                ->get();

            foreach ($links as $link) {
                if ($link->type !== 'content') {
                    continue;
                }

                DB::connection($this->connection)
                    ->table($table)
                    ->where('id', $link->prompt_resultable_id)
                    ->update(['prompt_result_id' => $link->prompt_result_id]);
            }
        }
    }

    private function dropLegacyForeignKeyColumns(): void
    {
        foreach ($this->promptResultTableNames() as $table) {
            if (Schema::connection($this->connection)->hasColumn($table, 'article_id')) {
                Schema::connection($this->connection)->table($table, function (Blueprint $table): void {
                    $table->dropConstrainedForeignId('article_id');
                });
            }
        }

        foreach ($this->articleTableNames() as $articleTable) {
            Schema::connection($this->connection)->table($articleTable, function (Blueprint $blueprint) use ($articleTable): void {
                $schema = Schema::connection($this->connection);

                if ($schema->hasColumn($articleTable, 'outline_result_id')) {
                    $blueprint->dropConstrainedForeignId('outline_result_id');
                }

                if ($schema->hasColumn($articleTable, 'content_result_id')) {
                    $blueprint->dropConstrainedForeignId('content_result_id');
                }

                if ($schema->hasColumn($articleTable, 'prompt_result_id')) {
                    $blueprint->dropConstrainedForeignId('prompt_result_id');
                }
            });
        }
    }

    private function restoreLegacyForeignKeyColumns(): void
    {
        foreach ($this->articleTableNames() as $articleTable) {
            Schema::connection($this->connection)->table($articleTable, function (Blueprint $blueprint) use ($articleTable): void {
                $schema = Schema::connection($this->connection);

                if (! $schema->hasColumn($articleTable, 'prompt_result_id')) {
                    $blueprint->foreignId('prompt_result_id')
                        ->nullable()
                        ->constrained('prompt_results')
                        ->nullOnDelete();
                }

                if (! $schema->hasColumn($articleTable, 'outline_result_id')) {
                    $blueprint->foreignId('outline_result_id')
                        ->nullable()
                        ->constrained('prompt_results')
                        ->nullOnDelete();
                }

                if (! $schema->hasColumn($articleTable, 'content_result_id')) {
                    $blueprint->foreignId('content_result_id')
                        ->nullable()
                        ->constrained('prompt_results')
                        ->nullOnDelete();
                }
            });
        }

        foreach ($this->promptResultTableNames() as $table) {
            if (! Schema::connection($this->connection)->hasColumn($table, 'article_id')) {
                Schema::connection($this->connection)->table($table, function (Blueprint $table): void {
                    $table->foreignId('article_id')
                        ->nullable()
                        ->constrained('articles')
                        ->nullOnDelete();
                });
            }
        }
    }

    /**
     * @return list<string>
     */
    private function articleTableNames(): array
    {
        return ['articles', 'seo_articles'];
    }

    /**
     * @return list<string>
     */
    private function promptResultTableNames(): array
    {
        return ['prompt_results', 'seo_prompt_results'];
    }
};
