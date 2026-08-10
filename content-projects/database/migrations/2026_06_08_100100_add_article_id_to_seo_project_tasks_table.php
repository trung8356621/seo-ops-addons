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
        if (Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'article_id')) {
            return;
        }

        Schema::connection($this->connection)->table('seo_project_tasks', function (Blueprint $table): void {
            $table->unsignedBigInteger('article_id')->nullable()->index()->after('site_id');
        });

        DB::connection($this->connection)->statement(
            <<<'SQL'
                UPDATE seo_project_tasks AS tasks
                INNER JOIN article_meta AS meta
                    ON meta.meta_key = 'content_project_run'
                    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(
                        IF(JSON_VALID(meta.meta_value), meta.meta_value, '{}'),
                        '$.task_id'
                    )) AS UNSIGNED) = tasks.id
                SET tasks.article_id = meta.article_id
                WHERE tasks.article_id IS NULL
            SQL
        );
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'article_id')) {
            return;
        }

        Schema::connection($this->connection)->table('seo_project_tasks', function (Blueprint $table): void {
            $table->dropColumn('article_id');
        });
    }
};
