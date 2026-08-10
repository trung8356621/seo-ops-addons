<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — SERP Intelligence schema foundation (omi_seo_ai).
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('seo_serp_queries')) {
            $schema->create('seo_serp_queries', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->unsignedBigInteger('workspace_id')->nullable()->index();
                $table->unsignedBigInteger('keyword_id')->nullable()->index();
                $table->unsignedBigInteger('cluster_id')->nullable()->index();
                $table->string('query', 500);
                $table->string('normalized_query', 500);
                // Hash canonical scope — tránh unique index vượt MySQL 3072 bytes (utf8mb4 + query 500).
                $table->char('identity_hash', 64);
                $table->string('language', 10)->default('');
                $table->string('country', 10)->default('');
                $table->string('location', 255)->default('');
                $table->string('device', 16)->default('desktop')->index();
                $table->string('search_engine', 32)->default('google');
                $table->string('provider_key', 64)->index();
                $table->string('status', 32)->default('active')->index();
                $table->string('latest_snapshot_ref', 64)->nullable()->index();
                $table->json('settings')->nullable();
                $table->json('metadata')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->timestamp('archived_at')->nullable();

                $table->unique(
                    ['tenant_id', 'site_id', 'identity_hash'],
                    'seo_serp_queries_identity_unique',
                );
                $table->index('normalized_query', 'seo_serp_queries_normalized_query_idx');
            });
        } elseif (! $schema->hasColumn('seo_serp_queries', 'identity_hash')) {
            // Repair partial fail: bảng đã tạo nhưng unique cũ quá dài / chưa có identity_hash.
            try {
                $schema->table('seo_serp_queries', function (Blueprint $table): void {
                    $table->dropUnique('seo_serp_queries_identity_unique');
                });
            } catch (\Throwable) {
                // index có thể chưa tồn tại sau fail trước
            }

            $schema->table('seo_serp_queries', function (Blueprint $table): void {
                $table->char('identity_hash', 64)->default('')->after('normalized_query');
                $table->unique(
                    ['tenant_id', 'site_id', 'identity_hash'],
                    'seo_serp_queries_identity_unique',
                );
                $table->index('normalized_query', 'seo_serp_queries_normalized_query_idx');
            });
        }

        if (! $schema->hasTable('seo_serp_snapshots')) {
            $schema->create('seo_serp_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->unsignedBigInteger('serp_query_id')->index();
                $table->string('provider_key', 64)->index();
                $table->string('provider_request_ref', 128)->nullable();
                $table->timestamp('captured_at')->index();
                $table->string('status', 32)->default('pending')->index();
                $table->unsignedInteger('result_count')->default(0);
                $table->unsignedInteger('organic_result_count')->default(0);
                $table->unsignedInteger('feature_count')->default(0);
                $table->string('locale', 16)->nullable();
                $table->string('location', 255)->nullable();
                $table->string('device', 16)->default('desktop');
                $table->string('search_engine', 32)->default('google');
                $table->string('raw_checksum', 64)->nullable();
                $table->string('normalized_checksum', 64)->nullable();
                $table->json('summary')->nullable();
                $table->json('analysis_summary')->nullable();
                $table->string('error_code', 96)->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('completed_at')->nullable();

                $table->index(['serp_query_id', 'captured_at'], 'seo_serp_snapshots_query_captured_idx');
                $table->index(['serp_query_id', 'normalized_checksum'], 'seo_serp_snapshots_query_checksum_idx');
            });
        }

        if (! $schema->hasTable('seo_serp_results')) {
            $schema->create('seo_serp_results', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('snapshot_id')->index();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->unsignedSmallInteger('position')->default(0)->index();
                $table->string('result_type', 32)->default('organic')->index();
                $table->string('url', 2048);
                $table->string('normalized_url', 2048);
                $table->string('domain', 255)->nullable()->index();
                $table->string('normalized_domain', 255)->nullable()->index();
                $table->string('title', 500)->nullable();
                $table->text('snippet')->nullable();
                $table->string('display_url', 500)->nullable();
                $table->string('page_type', 32)->nullable()->index();
                $table->string('search_intent', 32)->nullable()->index();
                $table->boolean('is_own_domain')->default(false);
                $table->boolean('is_competitor')->default(false);
                $table->boolean('is_featured')->default(false);
                $table->boolean('is_sponsored')->default(false);
                $table->timestamp('published_at')->nullable();
                $table->string('detected_language', 10)->nullable();
                $table->string('content_fingerprint', 64)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (! $schema->hasTable('seo_serp_features')) {
            $schema->create('seo_serp_features', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('snapshot_id')->index();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('feature_type', 32)->index();
                $table->unsignedSmallInteger('position')->nullable();
                $table->string('title', 500)->nullable();
                $table->text('text')->nullable();
                $table->string('source_url', 2048)->nullable();
                $table->string('source_domain', 255)->nullable();
                $table->text('question')->nullable();
                $table->text('answer_excerpt')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (! $schema->hasTable('seo_serp_page_evidence')) {
            $schema->create('seo_serp_page_evidence', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->unsignedBigInteger('snapshot_id')->index();
                $table->unsignedBigInteger('serp_result_id')->nullable()->index();
                $table->string('url', 2048);
                $table->string('normalized_url', 2048);
                $table->string('domain', 255)->nullable()->index();
                $table->string('fetch_status', 32)->default('pending')->index();
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->string('page_type', 32)->nullable()->index();
                $table->string('content_type', 128)->nullable();
                $table->string('search_intent', 32)->nullable();
                $table->string('title', 500)->nullable();
                $table->text('meta_description')->nullable();
                $table->string('canonical_url', 2048)->nullable();
                $table->json('headings')->nullable();
                $table->json('entities')->nullable();
                $table->json('schema_types')->nullable();
                $table->text('content_summary')->nullable();
                $table->unsignedInteger('word_count')->nullable();
                $table->unsignedInteger('media_count')->nullable();
                $table->unsignedInteger('table_count')->nullable();
                $table->unsignedInteger('faq_count')->nullable();
                $table->date('freshness_date')->nullable();
                $table->string('content_hash', 64)->nullable();
                $table->timestamp('analyzed_at')->nullable();
                $table->decimal('confidence', 5, 2)->nullable();
                $table->string('source', 32)->nullable();
                $table->string('error_code', 96)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('seo_serp_cluster_evidence')) {
            $schema->create('seo_serp_cluster_evidence', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('cluster_id')->index();
                $table->json('snapshot_refs')->nullable();
                $table->string('observed_intent', 32)->nullable();
                $table->json('observed_page_types')->nullable();
                $table->string('dominant_page_type', 32)->nullable();
                $table->decimal('serp_overlap_score', 5, 2)->nullable();
                $table->decimal('intent_consistency_score', 5, 2)->nullable();
                $table->decimal('cluster_confidence', 5, 2)->nullable();
                $table->string('recommended_action', 64)->nullable();
                $table->string('recommended_content_type', 64)->nullable();
                $table->json('reason_codes')->nullable();
                $table->json('warnings')->nullable();
                $table->string('status', 32)->default('draft')->index();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('seo_serp_content_gaps')) {
            $schema->create('seo_serp_content_gaps', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->unsignedBigInteger('workspace_id')->nullable()->index();
                $table->unsignedBigInteger('cluster_id')->nullable()->index();
                $table->unsignedBigInteger('keyword_id')->nullable()->index();
                $table->unsignedBigInteger('snapshot_id')->nullable()->index();
                $table->string('gap_type', 32)->index();
                $table->string('scope', 32)->nullable();
                $table->string('entity', 255)->nullable();
                $table->string('topic', 255)->nullable();
                $table->string('heading', 500)->nullable();
                $table->text('question')->nullable();
                $table->string('schema_type', 128)->nullable();
                $table->decimal('importance_score', 5, 2)->nullable();
                $table->decimal('confidence', 5, 2)->nullable();
                $table->json('evidence_result_refs')->nullable();
                $table->json('evidence_urls')->nullable();
                $table->string('recommended_action', 64)->nullable();
                $table->string('status', 32)->default('open')->index();
                $table->json('metadata')->nullable();
                $table->timestamp('detected_at')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        $schema->dropIfExists('seo_serp_content_gaps');
        $schema->dropIfExists('seo_serp_cluster_evidence');
        $schema->dropIfExists('seo_serp_page_evidence');
        $schema->dropIfExists('seo_serp_features');
        $schema->dropIfExists('seo_serp_results');
        $schema->dropIfExists('seo_serp_snapshots');
        $schema->dropIfExists('seo_serp_queries');
    }
};
