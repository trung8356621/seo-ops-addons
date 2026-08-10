<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enrich KI tables for full Phase 1 workflow (system empty — additive columns).
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('seo_keyword_workspaces')) {
            $schema->table('seo_keyword_workspaces', function (Blueprint $table) use ($schema): void {
                if (! $schema->hasColumn('seo_keyword_workspaces', 'settings')) {
                    $table->json('settings')->nullable()->after('country');
                }
                if (! $schema->hasColumn('seo_keyword_workspaces', 'summary')) {
                    $table->json('summary')->nullable()->after('settings');
                }
                if (! $schema->hasColumn('seo_keyword_workspaces', 'updated_by')) {
                    $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');
                }
            });
        }

        if ($schema->hasTable('seo_keywords')) {
            $schema->table('seo_keywords', function (Blueprint $table) use ($schema): void {
                if (! $schema->hasColumn('seo_keywords', 'tenant_id')) {
                    $table->unsignedBigInteger('tenant_id')->nullable()->index()->after('workspace_id');
                }
                if (! $schema->hasColumn('seo_keywords', 'site_id')) {
                    $table->unsignedBigInteger('site_id')->nullable()->index()->after('tenant_id');
                }
                if (! $schema->hasColumn('seo_keywords', 'language')) {
                    $table->string('language', 10)->nullable()->after('normalized_keyword');
                }
                if (! $schema->hasColumn('seo_keywords', 'country')) {
                    $table->string('country', 10)->nullable()->after('language');
                }
                if (! $schema->hasColumn('seo_keywords', 'secondary_intents')) {
                    $table->json('secondary_intents')->nullable()->after('search_intent');
                }
                if (! $schema->hasColumn('seo_keywords', 'is_primary')) {
                    $table->boolean('is_primary')->default(false)->after('is_duplicate');
                }
                if (! $schema->hasColumn('seo_keywords', 'is_excluded')) {
                    $table->boolean('is_excluded')->default(false)->index()->after('is_primary');
                }
                if (! $schema->hasColumn('seo_keywords', 'priority_score')) {
                    $table->decimal('priority_score', 6, 2)->nullable()->index()->after('total_score');
                }
                if (! $schema->hasColumn('seo_keywords', 'field_sources')) {
                    $table->json('field_sources')->nullable()->after('metadata');
                }
            });
        }

        if ($schema->hasTable('seo_keyword_clusters')) {
            $schema->table('seo_keyword_clusters', function (Blueprint $table) use ($schema): void {
                if (! $schema->hasColumn('seo_keyword_clusters', 'tenant_id')) {
                    $table->unsignedBigInteger('tenant_id')->nullable()->index()->after('workspace_id');
                }
                if (! $schema->hasColumn('seo_keyword_clusters', 'site_id')) {
                    $table->unsignedBigInteger('site_id')->nullable()->index()->after('tenant_id');
                }
                if (! $schema->hasColumn('seo_keyword_clusters', 'description')) {
                    $table->text('description')->nullable()->after('slug');
                }
                if (! $schema->hasColumn('seo_keyword_clusters', 'funnel_stage')) {
                    $table->string('funnel_stage', 32)->nullable()->after('search_intent');
                }
                if (! $schema->hasColumn('seo_keyword_clusters', 'relevance_score')) {
                    $table->decimal('relevance_score', 6, 2)->nullable()->after('keyword_count');
                }
                if (! $schema->hasColumn('seo_keyword_clusters', 'opportunity_score')) {
                    $table->decimal('opportunity_score', 6, 2)->nullable()->after('relevance_score');
                }
                if (! $schema->hasColumn('seo_keyword_clusters', 'priority_score')) {
                    $table->decimal('priority_score', 6, 2)->nullable()->index()->after('opportunity_score');
                }
                if (! $schema->hasColumn('seo_keyword_clusters', 'suggested_content_type')) {
                    $table->string('suggested_content_type', 32)->nullable()->after('priority_score');
                }
                if (! $schema->hasColumn('seo_keyword_clusters', 'suggested_title')) {
                    $table->string('suggested_title', 500)->nullable()->after('suggested_content_type');
                }
                if (! $schema->hasColumn('seo_keyword_clusters', 'suggested_description')) {
                    $table->text('suggested_description')->nullable()->after('suggested_title');
                }
                if (! $schema->hasColumn('seo_keyword_clusters', 'target_article_ref')) {
                    $table->string('target_article_ref', 64)->nullable()->after('suggested_description');
                }
                if (! $schema->hasColumn('seo_keyword_clusters', 'preserve_manual_primary')) {
                    $table->boolean('preserve_manual_primary')->default(false)->after('primary_keyword_id');
                }
            });
        }

        if ($schema->hasTable('seo_keyword_article_mappings')) {
            $schema->table('seo_keyword_article_mappings', function (Blueprint $table) use ($schema): void {
                if (! $schema->hasColumn('seo_keyword_article_mappings', 'tenant_id')) {
                    $table->unsignedBigInteger('tenant_id')->nullable()->index()->after('workspace_id');
                }
                if (! $schema->hasColumn('seo_keyword_article_mappings', 'site_id')) {
                    $table->unsignedBigInteger('site_id')->nullable()->index()->after('tenant_id');
                }
                if (! $schema->hasColumn('seo_keyword_article_mappings', 'article_ref')) {
                    $table->string('article_ref', 64)->nullable()->index()->after('article_id');
                }
                if (! $schema->hasColumn('seo_keyword_article_mappings', 'confidence')) {
                    $table->string('confidence', 16)->nullable()->after('mapping_type');
                }
                if (! $schema->hasColumn('seo_keyword_article_mappings', 'is_primary')) {
                    $table->boolean('is_primary')->default(false)->after('confidence');
                }
                if (! $schema->hasColumn('seo_keyword_article_mappings', 'status')) {
                    $table->string('status', 32)->default('active')->after('is_primary');
                }
                if (! $schema->hasColumn('seo_keyword_article_mappings', 'is_manual')) {
                    $table->boolean('is_manual')->default(false)->after('status');
                }
            });
        }
    }

    public function down(): void
    {
        // Additive enrichment — leave columns on down to avoid data loss.
    }
};
