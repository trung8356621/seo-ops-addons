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
        if (! $schema->hasTable('seo_project_tasks')
            || ! $schema->hasColumn('seo_project_tasks', 'article_id')) {
            return;
        }

        $indexRows = DB::connection($this->connection)->select(
            "SHOW INDEX FROM `seo_project_tasks` WHERE `Column_name` = 'article_id'"
        );

        $hasUnique = false;
        $dropNames = [];
        foreach ($indexRows as $row) {
            $keyName = (string) ($row->Key_name ?? '');
            if ($keyName === '' || $keyName === 'PRIMARY') {
                continue;
            }

            if ((int) ($row->Non_unique ?? 1) === 0) {
                $hasUnique = true;

                continue;
            }

            $dropNames[$keyName] = true;
        }

        if ($dropNames === [] && $hasUnique) {
            return;
        }

        $schema->table('seo_project_tasks', function (Blueprint $table) use ($dropNames, $hasUnique): void {
            foreach (array_keys($dropNames) as $indexName) {
                $table->dropIndex($indexName);
            }

            if (! $hasUnique) {
                $table->unique('article_id');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_project_tasks')
            || ! $schema->hasColumn('seo_project_tasks', 'article_id')) {
            return;
        }

        $schema->table('seo_project_tasks', function (Blueprint $table): void {
            try {
                $table->dropUnique(['article_id']);
            } catch (\Throwable) {
            }

            try {
                $table->index('article_id');
            } catch (\Throwable) {
            }
        });
    }
};
