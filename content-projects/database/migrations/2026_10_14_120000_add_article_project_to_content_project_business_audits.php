<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Correlate an existing business-audit row to Article + Content Project.
 * Does not create a second history table and does not rewrite prior rows.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_content_project_business_audits')) {
            return;
        }

        $missingProject = ! $schema->hasColumn('seo_content_project_business_audits', 'project_id');
        $missingArticle = ! $schema->hasColumn('seo_content_project_business_audits', 'article_id');

        if ($missingProject || $missingArticle) {
            $schema->table('seo_content_project_business_audits', function (Blueprint $table) use ($missingProject, $missingArticle): void {
                if ($missingProject) {
                    $table->unsignedBigInteger('project_id')->nullable()->index('cp_biz_audits_project_id_idx');
                }
                if ($missingArticle) {
                    $table->unsignedBigInteger('article_id')->nullable()->index('cp_biz_audits_article_id_idx');
                }
            });
        }

        if (! $this->indexExists('cp_biz_audits_article_project_idx')
            && $schema->hasColumn('seo_content_project_business_audits', 'article_id')
            && $schema->hasColumn('seo_content_project_business_audits', 'project_id')
        ) {
            $schema->table('seo_content_project_business_audits', function (Blueprint $table): void {
                $table->index(['article_id', 'project_id'], 'cp_biz_audits_article_project_idx');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_content_project_business_audits')) {
            return;
        }

        if ($this->indexExists('cp_biz_audits_article_project_idx')) {
            $schema->table('seo_content_project_business_audits', function (Blueprint $table): void {
                $table->dropIndex('cp_biz_audits_article_project_idx');
            });
        }

        $dropArticle = $schema->hasColumn('seo_content_project_business_audits', 'article_id');
        $dropProject = $schema->hasColumn('seo_content_project_business_audits', 'project_id');
        if (! $dropArticle && ! $dropProject) {
            return;
        }

        $schema->table('seo_content_project_business_audits', function (Blueprint $table) use ($dropArticle, $dropProject): void {
            if ($dropArticle) {
                if ($this->indexExists('cp_biz_audits_article_id_idx')) {
                    $table->dropIndex('cp_biz_audits_article_id_idx');
                }
                $table->dropColumn('article_id');
            }
            if ($dropProject) {
                if ($this->indexExists('cp_biz_audits_project_id_idx')) {
                    $table->dropIndex('cp_biz_audits_project_id_idx');
                }
                $table->dropColumn('project_id');
            }
        });
    }

    private function indexExists(string $name): bool
    {
        $rows = DB::connection($this->connection)->select(
            'SHOW INDEX FROM seo_content_project_business_audits WHERE Key_name = ?',
            [$name],
        );

        return $rows !== [];
    }
};
