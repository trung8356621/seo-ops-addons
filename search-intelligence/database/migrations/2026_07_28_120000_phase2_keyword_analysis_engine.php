<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — Keyword Analysis Engine: enrich operations tracking columns
 * (progress/cancel/idempotency/lock) + create the cannibalization issues
 * persistence table. Additive only — no destructive changes to Phase 1 data.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('seo_keyword_analysis_operations')) {
            $schema->table('seo_keyword_analysis_operations', function (Blueprint $table) use ($schema): void {
                if (! $schema->hasColumn('seo_keyword_analysis_operations', 'current_stage')) {
                    $table->string('current_stage', 32)->nullable()->after('stage');
                }
                if (! $schema->hasColumn('seo_keyword_analysis_operations', 'total_keywords')) {
                    $table->unsignedInteger('total_keywords')->default(0)->after('progress');
                }
                if (! $schema->hasColumn('seo_keyword_analysis_operations', 'processed_keywords')) {
                    $table->unsignedInteger('processed_keywords')->default(0)->after('total_keywords');
                }
                if (! $schema->hasColumn('seo_keyword_analysis_operations', 'failed_keywords')) {
                    $table->unsignedInteger('failed_keywords')->default(0)->after('processed_keywords');
                }
                if (! $schema->hasColumn('seo_keyword_analysis_operations', 'progress_percent')) {
                    $table->unsignedTinyInteger('progress_percent')->default(0)->after('failed_keywords');
                }
                if (! $schema->hasColumn('seo_keyword_analysis_operations', 'warnings_count')) {
                    $table->unsignedInteger('warnings_count')->default(0)->after('progress_percent');
                }
                if (! $schema->hasColumn('seo_keyword_analysis_operations', 'started_at')) {
                    $table->timestamp('started_at')->nullable()->after('error');
                }
                if (! $schema->hasColumn('seo_keyword_analysis_operations', 'finished_at')) {
                    $table->timestamp('finished_at')->nullable()->after('started_at');
                }
                if (! $schema->hasColumn('seo_keyword_analysis_operations', 'idempotency_key')) {
                    $table->string('idempotency_key', 128)->nullable()->after('finished_at')
                        ->index('seo_kw_ops_idempotency_key_index');
                }
                if (! $schema->hasColumn('seo_keyword_analysis_operations', 'cancel_requested')) {
                    $table->boolean('cancel_requested')->default(false)->after('idempotency_key');
                }
                if (! $schema->hasColumn('seo_keyword_analysis_operations', 'options')) {
                    $table->json('options')->nullable()->after('cancel_requested');
                }
                if (! $schema->hasColumn('seo_keyword_analysis_operations', 'keyword_scope')) {
                    $table->json('keyword_scope')->nullable()->after('options');
                }
                if (! $schema->hasColumn('seo_keyword_analysis_operations', 'lock_owner_token')) {
                    $table->string('lock_owner_token', 64)->nullable()->after('keyword_scope');
                }
            });
        }

        if (! $schema->hasTable('seo_keyword_cannibalization_issues')) {
            $schema->create('seo_keyword_cannibalization_issues', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('issue_type', 40)->index();
                $table->string('risk_level', 16)->index();
                $table->string('status', 16)->default('open')->index();
                $table->json('keyword_refs')->nullable();
                $table->json('cluster_refs')->nullable();
                $table->json('article_refs')->nullable();
                $table->json('reason_codes')->nullable();
                $table->text('summary')->nullable();
                $table->string('recommended_action', 64)->nullable();
                $table->decimal('confidence', 5, 2)->nullable();
                $table->string('source', 16)->default('rule');
                $table->string('fingerprint', 191)->index();
                $table->timestamp('detected_at')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->unsignedBigInteger('resolved_by')->nullable();
                $table->string('resolution_code', 64)->nullable();
                $table->timestamps();

                $table->unique(['workspace_id', 'fingerprint'], 'seo_kw_cannibalization_workspace_fingerprint_unique');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        $schema->dropIfExists('seo_keyword_cannibalization_issues');

        // Additive enrichment on seo_keyword_analysis_operations — leave columns on down to avoid data loss.
    }
};
