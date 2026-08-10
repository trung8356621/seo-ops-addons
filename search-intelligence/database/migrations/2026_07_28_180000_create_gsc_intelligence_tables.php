<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — GSC Intelligence schema foundation (omi_seo_ai).
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('seo_gsc_properties')) {
            $schema->create('seo_gsc_properties', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('provider_key', 64)->index();
                $table->string('property_uri', 2048);
                $table->char('identity_hash', 64);
                $table->string('property_type', 32)->default('url_prefix')->index();
                $table->string('display_name', 255)->nullable();
                $table->string('status', 32)->default('active')->index();
                $table->boolean('sync_enabled')->default(true);
                $table->string('default_country', 10)->nullable();
                $table->string('default_search_type', 32)->default('web');
                $table->string('timezone', 64)->default('UTC');
                $table->timestamp('last_synced_at')->nullable();
                $table->date('last_complete_date')->nullable();
                $table->string('last_error_code', 96)->nullable();
                $table->text('last_error_message')->nullable();
                $table->json('settings')->nullable();
                $table->json('metadata')->nullable();
                $table->unsignedBigInteger('legacy_mapping_id')->nullable()->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->timestamp('archived_at')->nullable();

                $table->unique(
                    ['tenant_id', 'site_id', 'identity_hash'],
                    'seo_gsc_properties_identity_unique',
                );
            });
        }

        if (! $schema->hasTable('seo_gsc_sync_runs')) {
            $schema->create('seo_gsc_sync_runs', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->unsignedBigInteger('property_id')->index();
                $table->string('provider_key', 64)->index();
                $table->string('operation_ref', 64)->nullable()->index();
                $table->date('date_from');
                $table->date('date_to');
                $table->string('search_type', 32)->default('web');
                $table->json('dimensions');
                $table->json('filters')->nullable();
                $table->string('status', 32)->default('accepted')->index();
                $table->unsignedInteger('requested_rows')->default(0);
                $table->unsignedInteger('received_rows')->default(0);
                $table->unsignedInteger('persisted_rows')->default(0);
                $table->unsignedInteger('skipped_rows')->default(0);
                $table->unsignedInteger('failed_rows')->default(0);
                $table->unsignedInteger('provider_request_count')->default(0);
                $table->decimal('provider_cost', 12, 4)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->string('result_code', 96)->nullable();
                $table->json('warnings')->nullable();
                $table->string('error_code', 96)->nullable();
                $table->text('error_message')->nullable();
                $table->char('idempotency_hash', 64);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique(
                    ['property_id', 'idempotency_hash'],
                    'seo_gsc_sync_runs_idempotency_unique',
                );
                $table->index(['property_id', 'date_from', 'date_to'], 'seo_gsc_sync_runs_property_range_idx');
            });
        }

        if (! $schema->hasTable('seo_gsc_daily_metrics')) {
            $schema->create('seo_gsc_daily_metrics', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->unsignedBigInteger('property_id')->index();
                $table->date('metric_date');
                $table->string('search_type', 32)->default('web');
                $table->string('query', 500)->nullable();
                $table->string('normalized_query', 500)->nullable();
                // Hash lookup — tránh index utf8mb4 vượt 3072 bytes trên URL dài.
                $table->char('normalized_query_hash', 64)->nullable();
                $table->string('page', 2048)->nullable();
                $table->string('normalized_page', 2048)->nullable();
                $table->char('normalized_page_hash', 64)->nullable();
                $table->string('country', 10)->nullable();
                $table->string('device', 16)->nullable();
                $table->string('search_appearance', 64)->nullable();
                $table->unsignedInteger('clicks')->default(0);
                $table->unsignedInteger('impressions')->default(0);
                $table->decimal('ctr', 8, 6)->nullable();
                $table->decimal('position', 8, 3)->nullable();
                $table->string('source', 32)->default('google_search_console');
                $table->string('source_ref', 128)->nullable();
                $table->char('data_hash', 64);
                $table->timestamps();

                $table->unique(
                    ['property_id', 'data_hash'],
                    'seo_gsc_daily_metrics_property_hash_unique',
                );
                $table->index(['property_id', 'metric_date'], 'seo_gsc_daily_metrics_property_date_idx');
                $table->index(
                    ['property_id', 'normalized_query_hash'],
                    'seo_gsc_daily_metrics_property_query_hash_idx',
                );
                $table->index(
                    ['property_id', 'normalized_page_hash'],
                    'seo_gsc_daily_metrics_property_page_hash_idx',
                );
            });
        } elseif (! $schema->hasColumn('seo_gsc_daily_metrics', 'normalized_page_hash')) {
            // Repair partial fail: bảng đã tạo nhưng index URL dài thất bại.
            try {
                $schema->table('seo_gsc_daily_metrics', function (Blueprint $table): void {
                    $table->dropIndex('seo_gsc_daily_metrics_property_query_idx');
                });
            } catch (\Throwable) {
                // index có thể chưa tồn tại
            }

            try {
                $schema->table('seo_gsc_daily_metrics', function (Blueprint $table): void {
                    $table->dropIndex('seo_gsc_daily_metrics_property_page_idx');
                });
            } catch (\Throwable) {
                // expected khi fail trước khi tạo index page
            }

            $schema->table('seo_gsc_daily_metrics', function (Blueprint $table): void {
                $table->char('normalized_query_hash', 64)->nullable()->after('normalized_query');
                $table->char('normalized_page_hash', 64)->nullable()->after('normalized_page');
                $table->index(
                    ['property_id', 'normalized_query_hash'],
                    'seo_gsc_daily_metrics_property_query_hash_idx',
                );
                $table->index(
                    ['property_id', 'normalized_page_hash'],
                    'seo_gsc_daily_metrics_property_page_hash_idx',
                );
            });
        }

        if (! $schema->hasTable('seo_gsc_query_mappings')) {
            $schema->create('seo_gsc_query_mappings', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->unsignedBigInteger('property_id')->index();
                $table->string('normalized_query', 500);
                $table->char('identity_hash', 64);
                $table->string('sample_query', 500)->nullable();
                $table->unsignedBigInteger('keyword_id')->nullable()->index();
                $table->unsignedBigInteger('cluster_id')->nullable()->index();
                $table->unsignedBigInteger('topic_id')->nullable()->index();
                $table->string('mapping_type', 32)->default('unmapped')->index();
                $table->decimal('confidence', 5, 2)->nullable();
                $table->string('source', 32)->nullable();
                $table->string('status', 32)->default('candidate')->index();
                $table->json('reason_codes')->nullable();
                $table->json('metadata')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['property_id', 'identity_hash'],
                    'seo_gsc_query_mappings_property_identity_unique',
                );
            });
        }

        if (! $schema->hasTable('seo_gsc_page_mappings')) {
            $schema->create('seo_gsc_page_mappings', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->unsignedBigInteger('property_id')->index();
                $table->string('page', 2048);
                $table->string('normalized_page', 2048);
                $table->char('identity_hash', 64);
                $table->string('article_ref', 64)->nullable()->index();
                $table->string('content_project_ref', 64)->nullable()->index();
                $table->string('project_item_ref', 64)->nullable()->index();
                $table->string('mapping_type', 32)->default('unmapped')->index();
                $table->decimal('confidence', 5, 2)->nullable();
                $table->string('source', 32)->nullable();
                $table->string('status', 32)->default('candidate')->index();
                $table->json('reason_codes')->nullable();
                $table->json('metadata')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['property_id', 'identity_hash'],
                    'seo_gsc_page_mappings_property_identity_unique',
                );
            });
        }

        if (! $schema->hasTable('seo_gsc_performance_aggregates')) {
            $schema->create('seo_gsc_performance_aggregates', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->unsignedBigInteger('property_id')->index();
                $table->string('scope_type', 32)->index();
                $table->string('scope_ref', 64)->nullable()->index();
                $table->string('period_type', 32)->index();
                $table->date('date_from');
                $table->date('date_to');
                $table->date('comparison_date_from')->nullable();
                $table->date('comparison_date_to')->nullable();
                $table->unsignedInteger('clicks')->default(0);
                $table->unsignedInteger('impressions')->default(0);
                $table->decimal('ctr', 8, 6)->nullable();
                $table->decimal('position', 8, 3)->nullable();
                $table->integer('clicks_delta')->nullable();
                $table->integer('impressions_delta')->nullable();
                $table->decimal('ctr_delta', 8, 6)->nullable();
                $table->decimal('position_delta', 8, 3)->nullable();
                $table->unsignedInteger('query_count')->default(0);
                $table->unsignedInteger('page_count')->default(0);
                $table->json('summary')->nullable();
                $table->timestamp('calculated_at')->nullable();
                $table->string('algorithm_version', 32)->nullable();
                $table->char('data_hash', 64);
                $table->timestamps();

                $table->unique(
                    ['property_id', 'data_hash'],
                    'seo_gsc_performance_aggregates_property_hash_unique',
                );
                $table->index(
                    ['property_id', 'scope_type', 'period_type', 'date_from', 'date_to'],
                    'seo_gsc_performance_aggregates_scope_period_idx',
                );
            });
        }

        if (! $schema->hasTable('seo_gsc_opportunities')) {
            $schema->create('seo_gsc_opportunities', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->unsignedBigInteger('property_id')->index();
                $table->string('opportunity_type', 64)->index();
                $table->string('scope_type', 32)->index();
                $table->string('scope_ref', 64)->nullable()->index();
                $table->string('query_mapping_ref', 64)->nullable()->index();
                $table->string('page_mapping_ref', 64)->nullable()->index();
                $table->string('risk_level', 16)->nullable()->index();
                $table->decimal('priority_score', 5, 2)->nullable();
                $table->decimal('confidence', 5, 2)->nullable();
                $table->date('date_from');
                $table->date('date_to');
                $table->date('comparison_date_from')->nullable();
                $table->date('comparison_date_to')->nullable();
                $table->json('evidence');
                $table->json('reason_codes')->nullable();
                $table->string('recommended_action', 64)->nullable();
                $table->string('status', 32)->default('open')->index();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->string('resolution_code', 64)->nullable();
                $table->char('fingerprint', 64);
                $table->timestamps();

                $table->unique(
                    ['property_id', 'fingerprint'],
                    'seo_gsc_opportunities_property_fingerprint_unique',
                );
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        $schema->dropIfExists('seo_gsc_opportunities');
        $schema->dropIfExists('seo_gsc_performance_aggregates');
        $schema->dropIfExists('seo_gsc_page_mappings');
        $schema->dropIfExists('seo_gsc_query_mappings');
        $schema->dropIfExists('seo_gsc_daily_metrics');
        $schema->dropIfExists('seo_gsc_sync_runs');
        $schema->dropIfExists('seo_gsc_properties');
    }
};
