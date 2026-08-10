<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('seo_keyword_workspaces')) {
            $schema->create('seo_keyword_workspaces', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('name', 255);
                $table->text('description')->nullable();
                $table->string('status', 32)->default('draft')->index();
                $table->string('clustering_strategy', 32)->nullable();
                $table->string('language', 10)->nullable();
                $table->string('country', 10)->nullable();
                $table->unsignedInteger('keyword_count')->default(0);
                $table->unsignedInteger('cluster_count')->default(0);
                $table->unsignedInteger('topic_count')->default(0);
                $table->timestamp('last_analyzed_at')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('seo_keywords')) {
            $schema->create('seo_keywords', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->string('keyword', 500);
                $table->string('normalized_keyword', 500);
                $table->string('source', 32)->default('manual')->index();
                $table->unsignedInteger('search_volume')->nullable();
                $table->decimal('keyword_difficulty', 5, 2)->nullable();
                $table->decimal('cpc', 10, 2)->nullable();
                $table->decimal('competition', 5, 2)->nullable();
                $table->string('search_intent', 32)->nullable()->index();
                $table->string('funnel_stage', 32)->nullable();
                $table->string('analysis_status', 32)->default('pending')->index();
                $table->string('review_status', 32)->default('unreviewed')->index();
                $table->decimal('relevance_score', 6, 2)->nullable();
                $table->decimal('business_value_score', 6, 2)->nullable();
                $table->decimal('opportunity_score', 6, 2)->nullable();
                $table->decimal('intent_score', 6, 2)->nullable();
                $table->decimal('total_score', 6, 2)->nullable();
                $table->boolean('is_duplicate')->default(false)->index();
                $table->unsignedBigInteger('duplicate_of_keyword_id')->nullable()->index();
                $table->unsignedBigInteger('cluster_id')->nullable()->index();
                $table->unsignedBigInteger('topic_id')->nullable()->index();
                $table->json('serp_features')->nullable();
                $table->json('metadata')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('imported_by')->nullable();
                $table->timestamp('analyzed_at')->nullable();
                $table->timestamps();

                $table->unique(['workspace_id', 'normalized_keyword'], 'seo_kw_workspace_normalized_unique');
            });
        }

        if (! $schema->hasTable('seo_keyword_clusters')) {
            $schema->create('seo_keyword_clusters', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('topic_id')->nullable()->index();
                $table->unsignedBigInteger('primary_keyword_id')->nullable()->index();
                $table->string('name', 255);
                $table->string('slug', 255)->nullable();
                $table->string('cluster_type', 32)->default('cluster')->index();
                $table->string('status', 32)->default('draft')->index();
                $table->string('search_intent', 32)->nullable();
                $table->unsignedInteger('total_search_volume')->nullable();
                $table->decimal('avg_keyword_difficulty', 5, 2)->nullable();
                $table->unsignedInteger('keyword_count')->default(0);
                $table->string('content_project_ref', 64)->nullable()->index();
                $table->string('content_project_item_ref', 64)->nullable()->index();
                $table->timestamp('converted_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('seo_topics')) {
            $schema->create('seo_topics', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('parent_id')->nullable()->index();
                $table->string('name', 255);
                $table->string('slug', 255)->nullable();
                $table->string('topic_type', 32)->default('subtopic')->index();
                $table->string('status', 32)->default('draft')->index();
                $table->unsignedInteger('depth')->default(0);
                $table->string('path', 500)->nullable();
                $table->text('description')->nullable();
                $table->unsignedInteger('keyword_count')->default(0);
                $table->unsignedInteger('cluster_count')->default(0);
                $table->unsignedInteger('total_search_volume')->nullable();
                $table->decimal('score', 6, 2)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('seo_topic_cluster_links')) {
            $schema->create('seo_topic_cluster_links', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('topic_id')->index();
                $table->unsignedBigInteger('cluster_id')->index();
                $table->string('relationship', 32)->default('primary')->index();
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();

                $table->unique(['topic_id', 'cluster_id'], 'seo_topic_cluster_links_unique');
            });
        }

        if (! $schema->hasTable('seo_keyword_relationships')) {
            $schema->create('seo_keyword_relationships', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('keyword_id')->index();
                $table->unsignedBigInteger('related_keyword_id')->index();
                $table->string('relationship_type', 32)->index();
                $table->decimal('confidence', 5, 2)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['keyword_id', 'related_keyword_id', 'relationship_type'], 'seo_kw_relationships_unique');
            });
        }

        if (! $schema->hasTable('seo_keyword_article_mappings')) {
            $schema->create('seo_keyword_article_mappings', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('keyword_id')->index();
                $table->unsignedBigInteger('article_id')->nullable()->index();
                $table->string('mapping_type', 32)->index();
                $table->string('external_reference', 255)->nullable();
                $table->unsignedInteger('rank_position')->nullable();
                $table->text('notes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('seo_topical_map_versions')) {
            $schema->create('seo_topical_map_versions', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedInteger('version')->default(1);
                $table->string('status', 32)->default('draft')->index();
                $table->json('snapshot')->nullable();
                $table->json('summary')->nullable();
                $table->unsignedBigInteger('generated_by')->nullable();
                $table->timestamp('generated_at')->nullable();
                $table->timestamps();

                $table->unique(['workspace_id', 'version'], 'seo_topical_map_versions_unique');
            });
        }

        if (! $schema->hasTable('seo_keyword_analysis_operations')) {
            $schema->create('seo_keyword_analysis_operations', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('status', 32)->default('pending')->index();
                $table->string('stage', 32)->nullable()->index();
                $table->unsignedInteger('progress')->default(0);
                $table->string('result_code', 96)->nullable();
                $table->json('summary')->nullable();
                $table->text('error')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        $schema->dropIfExists('seo_keyword_analysis_operations');
        $schema->dropIfExists('seo_topical_map_versions');
        $schema->dropIfExists('seo_keyword_article_mappings');
        $schema->dropIfExists('seo_keyword_relationships');
        $schema->dropIfExists('seo_topic_cluster_links');
        $schema->dropIfExists('seo_topics');
        $schema->dropIfExists('seo_keyword_clusters');
        $schema->dropIfExists('seo_keywords');
        $schema->dropIfExists('seo_keyword_workspaces');
    }
};
