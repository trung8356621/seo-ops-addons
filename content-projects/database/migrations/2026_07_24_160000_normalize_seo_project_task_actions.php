<?php

declare(strict_types=1);

use Omnichannel\Addons\ContentProjects\Support\ProjectTaskSourceKeyGenerator;
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

        $schema->table('seo_project_tasks', function (Blueprint $table): void {
            if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'keyword')) {
                $table->string('keyword', 500)->nullable()->after('source_content');
            }
            if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'title')) {
                $table->string('title', 500)->nullable()->after('keyword');
            }
            if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'secondary_description')) {
                $table->text('secondary_description')->nullable()->after('description')
                    ->comment('Optional article context for create/rewrite prompts');
            }
        });

        $typeDef = strtolower((string) (
            DB::connection($this->connection)->selectOne("SHOW COLUMNS FROM seo_project_tasks LIKE 'type'")->Type ?? ''
        ));
        $alreadyNormalized = str_contains($typeDef, 'create')
            && ! str_contains($typeDef, 'new_keyword')
            && ! str_contains($typeDef, 'new_title');

        if (! $alreadyNormalized) {
            DB::connection($this->connection)->statement(
                "ALTER TABLE seo_project_tasks MODIFY COLUMN type ENUM('rewrite', 'new_keyword', 'new_title', 'improve', 'create') NOT NULL COMMENT 'create|rewrite|improve (legacy new_* remapped)'",
            );

            DB::connection($this->connection)->update(
                "UPDATE seo_project_tasks SET keyword = TRIM(source_content), type = 'create' WHERE type = 'new_keyword'",
            );
            DB::connection($this->connection)->update(
                "UPDATE seo_project_tasks SET title = TRIM(source_content), type = 'create' WHERE type = 'new_title'",
            );
        }

        // Rewrite cũ: giữ Existing Article ở source_content; seed Title để validation mới không gãy.
        DB::connection($this->connection)->update(
            "UPDATE seo_project_tasks
             SET title = TRIM(source_content)
             WHERE type = 'rewrite'
               AND (title IS NULL OR TRIM(title) = '')
               AND TRIM(COALESCE(source_content, '')) <> ''",
        );

        if (! $alreadyNormalized) {
            DB::connection($this->connection)->statement(
                "ALTER TABLE seo_project_tasks MODIFY COLUMN type ENUM('create', 'rewrite', 'improve') NOT NULL COMMENT 'create: viết mới, rewrite: viết lại, improve: prompt improve'",
            );
        }

        $this->regenerateSourceKeys();
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement(
            "ALTER TABLE seo_project_tasks MODIFY COLUMN type ENUM('create', 'rewrite', 'improve', 'new_keyword', 'new_title') NOT NULL",
        );

        DB::connection($this->connection)->update(
            "UPDATE seo_project_tasks SET type = 'new_keyword' WHERE type = 'create' AND TRIM(COALESCE(keyword, '')) <> '' AND TRIM(COALESCE(title, '')) = ''",
        );
        DB::connection($this->connection)->update(
            "UPDATE seo_project_tasks SET type = 'new_title' WHERE type = 'create'",
        );

        DB::connection($this->connection)->statement(
            "ALTER TABLE seo_project_tasks MODIFY COLUMN type ENUM('rewrite', 'new_keyword', 'new_title', 'improve') NOT NULL COMMENT 'rewrite|new_keyword|new_title|improve'",
        );

        Schema::connection($this->connection)->table('seo_project_tasks', function (Blueprint $table): void {
            foreach (['secondary_description', 'title', 'keyword'] as $column) {
                if (Schema::connection($this->connection)->hasColumn('seo_project_tasks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function regenerateSourceKeys(): void
    {
        $generator = new ProjectTaskSourceKeyGenerator;
        $connection = DB::connection($this->connection);

        $connection->table('seo_project_tasks')
            ->orderBy('id')
            ->select(['id', 'project_id', 'type', 'post_type', 'source_content'])
            ->chunkById(200, function ($rows) use ($connection, $generator): void {
                foreach ($rows as $row) {
                    $sourceKey = $generator->generate(
                        (int) $row->project_id,
                        (string) $row->type,
                        $row->post_type !== null ? (string) $row->post_type : null,
                        (string) ($row->source_content ?? ''),
                    );
                    $connection->table('seo_project_tasks')
                        ->where('id', (int) $row->id)
                        ->update(['source_key' => $sourceKey]);
                }
            });
    }
};
