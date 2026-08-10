<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project-unit archive: flag trên seo_projects + mở rộng header/items đã có.
 * Không tạo bảng mới. Không đụng seo_content_archive_items (legacy bài lẻ).
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('seo_projects')) {
            $schema->table('seo_projects', function (Blueprint $table) use ($schema): void {
                if (! $schema->hasColumn('seo_projects', 'archived_at')) {
                    $table->timestamp('archived_at')->nullable()->after('updated_at')->index();
                }
                if (! $schema->hasColumn('seo_projects', 'archived_by')) {
                    $table->unsignedBigInteger('archived_by')->nullable()->after('archived_at')->index();
                }
            });
        }

        if ($schema->hasTable('seo_project_archives')) {
            $schema->table('seo_project_archives', function (Blueprint $table) use ($schema): void {
                if (! $schema->hasColumn('seo_project_archives', 'site_id')) {
                    $table->unsignedBigInteger('site_id')->nullable()->after('project_id')->index();
                }
                if (! $schema->hasColumn('seo_project_archives', 'owner_id')) {
                    $table->unsignedBigInteger('owner_id')->nullable()->after('site_id')->index();
                }
                if (! $schema->hasColumn('seo_project_archives', 'project_name')) {
                    $table->string('project_name')->nullable()->after('owner_id');
                }
                if (! $schema->hasColumn('seo_project_archives', 'project_month')) {
                    $table->unsignedTinyInteger('project_month')->nullable()->after('project_name');
                }
                if (! $schema->hasColumn('seo_project_archives', 'project_year')) {
                    $table->unsignedSmallInteger('project_year')->nullable()->after('project_month');
                }
                if (! $schema->hasColumn('seo_project_archives', 'total_articles')) {
                    $table->unsignedInteger('total_articles')->default(0)->after('articles_count');
                }
                if (! $schema->hasColumn('seo_project_archives', 'completed_articles')) {
                    $table->unsignedInteger('completed_articles')->default(0)->after('total_articles');
                }
                if (! $schema->hasColumn('seo_project_archives', 'approved_articles')) {
                    $table->unsignedInteger('approved_articles')->default(0)->after('completed_articles');
                }
                if (! $schema->hasColumn('seo_project_archives', 'synced_articles')) {
                    $table->unsignedInteger('synced_articles')->default(0)->after('approved_articles');
                }
                if (! $schema->hasColumn('seo_project_archives', 'average_seo_score')) {
                    $table->decimal('average_seo_score', 5, 2)->nullable()->after('synced_articles');
                }
                if (! $schema->hasColumn('seo_project_archives', 'summary_snapshot')) {
                    $table->json('summary_snapshot')->nullable()->after('average_seo_score');
                }
                if (! $schema->hasColumn('seo_project_archives', 'archived_at')) {
                    $table->timestamp('archived_at')->nullable()->after('note')->index();
                }
                if (! $schema->hasColumn('seo_project_archives', 'restored_by')) {
                    $table->unsignedBigInteger('restored_by')->nullable()->after('archived_at')->index();
                }
                if (! $schema->hasColumn('seo_project_archives', 'restored_at')) {
                    $table->timestamp('restored_at')->nullable()->after('restored_by')->index();
                }
            });
        }

        if ($schema->hasTable('seo_project_archive_items')) {
            $schema->table('seo_project_archive_items', function (Blueprint $table) use ($schema): void {
                if (! $schema->hasColumn('seo_project_archive_items', 'task_id')) {
                    $table->unsignedBigInteger('task_id')->nullable()->after('article_id')->index();
                }
                if (! $schema->hasColumn('seo_project_archive_items', 'position')) {
                    $table->unsignedInteger('position')->nullable()->after('task_id');
                }
                if (! $schema->hasColumn('seo_project_archive_items', 'article_snapshot')) {
                    $table->json('article_snapshot')->nullable()->after('position');
                }
                if (! $schema->hasColumn('seo_project_archive_items', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable()->after('created_at');
                }
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('seo_project_archive_items')) {
            $schema->table('seo_project_archive_items', function (Blueprint $table) use ($schema): void {
                foreach (['article_snapshot', 'position', 'task_id', 'updated_at'] as $column) {
                    if ($schema->hasColumn('seo_project_archive_items', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if ($schema->hasTable('seo_project_archives')) {
            $schema->table('seo_project_archives', function (Blueprint $table) use ($schema): void {
                foreach ([
                    'restored_at',
                    'restored_by',
                    'archived_at',
                    'summary_snapshot',
                    'average_seo_score',
                    'synced_articles',
                    'approved_articles',
                    'completed_articles',
                    'total_articles',
                    'project_year',
                    'project_month',
                    'project_name',
                    'owner_id',
                    'site_id',
                ] as $column) {
                    if ($schema->hasColumn('seo_project_archives', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if ($schema->hasTable('seo_projects')) {
            $schema->table('seo_projects', function (Blueprint $table) use ($schema): void {
                foreach (['archived_by', 'archived_at'] as $column) {
                    if ($schema->hasColumn('seo_projects', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
