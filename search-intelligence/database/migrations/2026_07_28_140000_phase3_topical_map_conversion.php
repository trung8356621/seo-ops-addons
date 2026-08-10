<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — Topical Map versions enrich + link suggestions + conversion/traceability.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('seo_topical_map_versions')) {
            $schema->table('seo_topical_map_versions', function (Blueprint $table) use ($schema): void {
                if (! $schema->hasColumn('seo_topical_map_versions', 'status')) {
                    $table->string('status', 32)->default('draft')->index();
                }
                if (! $schema->hasColumn('seo_topical_map_versions', 'mode')) {
                    $table->string('mode', 32)->nullable();
                }
                if (! $schema->hasColumn('seo_topical_map_versions', 'approved_at')) {
                    $table->timestamp('approved_at')->nullable();
                }
                if (! $schema->hasColumn('seo_topical_map_versions', 'approved_by')) {
                    $table->unsignedBigInteger('approved_by')->nullable();
                }
                if (! $schema->hasColumn('seo_topical_map_versions', 'superseded_by_version_id')) {
                    $table->unsignedBigInteger('superseded_by_version_id')->nullable()->index();
                }
                if (! $schema->hasColumn('seo_topical_map_versions', 'tenant_id')) {
                    $table->unsignedBigInteger('tenant_id')->nullable()->index();
                }
                if (! $schema->hasColumn('seo_topical_map_versions', 'site_id')) {
                    $table->unsignedBigInteger('site_id')->nullable()->index();
                }
            });
        }

        if (! $schema->hasTable('seo_topical_link_suggestions')) {
            $schema->create('seo_topical_link_suggestions', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('site_id')->nullable()->index();
                $table->unsignedBigInteger('topical_map_version_id')->nullable()->index();
                $table->unsignedBigInteger('source_article_id')->nullable()->index();
                $table->unsignedBigInteger('source_cluster_id')->nullable()->index();
                $table->unsignedBigInteger('target_article_id')->nullable()->index();
                $table->unsignedBigInteger('target_cluster_id')->nullable()->index();
                $table->string('relationship', 64)->index();
                $table->unsignedBigInteger('anchor_keyword_id')->nullable()->index();
                $table->decimal('priority', 8, 2)->nullable();
                $table->decimal('confidence', 5, 2)->nullable();
                $table->json('reason_codes')->nullable();
                $table->string('status', 32)->default('draft')->index();
                $table->string('fingerprint', 128)->index();
                $table->timestamps();
                $table->unique(['workspace_id', 'fingerprint'], 'seo_topical_link_sug_ws_fp_unique');
            });
        }

        if (! $schema->hasTable('seo_keyword_project_conversions')) {
            $schema->create('seo_keyword_project_conversions', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('site_id')->nullable()->index();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('topical_map_version_id')->index();
                $table->string('content_project_ref', 64)->nullable()->index();
                $table->string('status', 32)->default('previewed')->index();
                $table->string('idempotency_key_hash', 128)->nullable()->index();
                $table->json('selected_cluster_refs')->nullable();
                $table->json('summary')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('seo_keyword_content_project_links')) {
            $schema->create('seo_keyword_content_project_links', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('site_id')->nullable()->index();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('topical_map_version_id')->index();
                $table->unsignedBigInteger('topic_id')->nullable()->index();
                $table->unsignedBigInteger('cluster_id')->index();
                $table->unsignedBigInteger('keyword_id')->nullable()->index();
                $table->string('content_project_ref', 64)->index();
                $table->string('project_item_ref', 64)->nullable()->index();
                $table->unsignedBigInteger('conversion_id')->nullable()->index();
                $table->string('relationship', 64)->default('origin')->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('seo_keyword_content_project_links');
        $schema->dropIfExists('seo_keyword_project_conversions');
        $schema->dropIfExists('seo_topical_link_suggestions');
    }
};
