<?php

declare(strict_types=1);

// Phase 6 — Agent Workspace observability / evaluation / governance (omi_seo_ai).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('seo_agent_traces')) {
            $schema->create('seo_agent_traces', function (Blueprint $table): void {
                $table->id();
                $table->string('trace_id', 64)->unique();
                $table->string('connection_hash', 64)->nullable()->index();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('site_id')->nullable()->index();
                $table->string('site_ref', 128)->nullable()->index();
                $table->unsignedBigInteger('actor_user_id')->nullable()->index();
                $table->string('root_span_type', 64)->nullable();
                $table->string('status', 32)->default('open')->index();
                $table->json('references_json')->nullable();
                $table->json('version_snapshot')->nullable();
                $table->timestamp('started_at')->nullable()->index();
                $table->timestamp('finished_at')->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->timestamps();

                $table->index(['connection_hash', 'started_at'], 'seo_agent_traces_conn_started_index');
            });
        }

        if (! $schema->hasTable('seo_agent_trace_spans')) {
            $schema->create('seo_agent_trace_spans', function (Blueprint $table): void {
                $table->id();
                $table->string('trace_id', 64)->index();
                $table->string('span_id', 64)->unique();
                $table->string('parent_span_id', 64)->nullable()->index();
                $table->string('span_type', 64)->index();
                $table->string('status', 32)->default('ok')->index();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->json('attributes')->nullable();
                $table->json('references_json')->nullable();
                $table->string('error_code', 64)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['trace_id', 'started_at'], 'seo_agent_spans_trace_started_index');
            });
        }

        if (! $schema->hasTable('seo_agent_metric_events')) {
            $schema->create('seo_agent_metric_events', function (Blueprint $table): void {
                $table->id();
                $table->string('hash_id', 64)->unique();
                $table->string('metric_key', 96)->index();
                $table->string('trace_id', 64)->nullable()->index();
                $table->unsignedBigInteger('site_id')->nullable()->index();
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->json('dimensions')->nullable();
                $table->decimal('value', 14, 4)->default(1);
                $table->string('severity', 16)->default('info');
                $table->timestamp('occurred_at')->index();
                $table->timestamps();

                $table->index(['metric_key', 'occurred_at'], 'seo_agent_metric_events_key_time_index');
            });
        }

        if (! $schema->hasTable('seo_agent_metric_aggregates')) {
            $schema->create('seo_agent_metric_aggregates', function (Blueprint $table): void {
                $table->id();
                $table->string('metric_key', 96);
                $table->string('bucket', 32);
                $table->date('bucket_date');
                $table->unsignedBigInteger('site_id')->default(0);
                $table->string('dim_hash', 64)->default('');
                $table->json('dimensions')->nullable();
                $table->decimal('value_sum', 18, 4)->default(0);
                $table->unsignedInteger('value_count')->default(0);
                $table->timestamps();

                $table->unique(
                    ['metric_key', 'bucket', 'bucket_date', 'site_id', 'dim_hash'],
                    'seo_agent_metric_agg_unique',
                );
                $table->index(['bucket', 'bucket_date'], 'seo_agent_metric_agg_bucket_index');
            });
        }

        if (! $schema->hasTable('seo_agent_evaluation_datasets')) {
            $schema->create('seo_agent_evaluation_datasets', function (Blueprint $table): void {
                $table->id();
                $table->string('hash_id', 64)->unique();
                $table->string('key', 96)->unique();
                $table->string('name', 255);
                $table->string('version', 32)->default('1');
                $table->text('description')->nullable();
                $table->boolean('enabled')->default(true);
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('seo_agent_evaluation_cases')) {
            $schema->create('seo_agent_evaluation_cases', function (Blueprint $table): void {
                $table->id();
                $table->string('hash_id', 64)->unique();
                $table->unsignedBigInteger('dataset_id')->index();
                $table->string('source', 32)->default('manual');
                $table->string('title', 255);
                $table->text('input_message');
                $table->json('context_fixture')->nullable();
                $table->json('skill_fixture')->nullable();
                $table->json('knowledge_fixture_refs')->nullable();
                $table->string('expected_response_type', 64)->nullable();
                $table->json('expected_skill_keys')->nullable();
                $table->json('forbidden_skills')->nullable();
                $table->json('expected_clarification_keys')->nullable();
                $table->json('expected_step_order')->nullable();
                $table->json('required_safety')->nullable();
                $table->json('tags')->nullable();
                $table->boolean('enabled')->default(true);
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('seo_agent_evaluation_runs')) {
            $schema->create('seo_agent_evaluation_runs', function (Blueprint $table): void {
                $table->id();
                $table->string('hash_id', 64)->unique();
                $table->unsignedBigInteger('dataset_id')->index();
                $table->string('status', 32)->default('pending')->index();
                $table->string('candidate_label', 128)->nullable();
                $table->string('baseline_run_hash', 64)->nullable();
                $table->json('config_snapshot')->nullable();
                $table->json('summary')->nullable();
                $table->string('gate_status', 32)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->boolean('dry_run')->default(false);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('seo_agent_evaluation_results')) {
            $schema->create('seo_agent_evaluation_results', function (Blueprint $table): void {
                $table->id();
                $table->string('hash_id', 64)->unique();
                $table->unsignedBigInteger('run_id')->index();
                $table->unsignedBigInteger('case_id')->index();
                $table->string('status', 32)->default('scored');
                $table->decimal('score', 8, 4)->nullable();
                $table->json('scores')->nullable();
                $table->json('observed')->nullable();
                $table->json('violations')->nullable();
                $table->unsignedInteger('latency_ms')->nullable();
                $table->json('token_usage')->nullable();
                $table->timestamps();

                $table->unique(['run_id', 'case_id'], 'seo_agent_eval_results_run_case_unique');
            });
        }

        if (! $schema->hasTable('seo_agent_reviews')) {
            $schema->create('seo_agent_reviews', function (Blueprint $table): void {
                $table->id();
                $table->string('hash_id', 64)->unique();
                $table->string('trace_id', 64)->nullable()->index();
                $table->unsignedBigInteger('site_id')->nullable()->index();
                $table->string('reason', 64)->index();
                $table->string('severity', 16)->default('warning')->index();
                $table->string('status', 32)->default('open')->index();
                $table->json('payload')->nullable();
                $table->unsignedBigInteger('assigned_to')->nullable()->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('rating', 32)->nullable();
                $table->string('expected_skill')->nullable();
                $table->text('comment')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('seo_agent_feedback')) {
            $schema->create('seo_agent_feedback', function (Blueprint $table): void {
                $table->id();
                $table->string('hash_id', 64)->unique();
                $table->string('trace_id', 64)->nullable()->index();
                $table->unsignedBigInteger('conversation_id')->nullable()->index();
                $table->unsignedBigInteger('message_id')->nullable()->index();
                $table->unsignedBigInteger('actor_user_id')->index();
                $table->unsignedBigInteger('site_id')->nullable()->index();
                $table->boolean('useful')->default(true);
                $table->string('reason', 64)->nullable();
                $table->text('comment')->nullable();
                $table->timestamps();

                $table->index(['message_id', 'actor_user_id'], 'seo_agent_feedback_msg_user_index');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        foreach ([
            'seo_agent_feedback',
            'seo_agent_reviews',
            'seo_agent_evaluation_results',
            'seo_agent_evaluation_runs',
            'seo_agent_evaluation_cases',
            'seo_agent_evaluation_datasets',
            'seo_agent_metric_aggregates',
            'seo_agent_metric_events',
            'seo_agent_trace_spans',
            'seo_agent_traces',
        ] as $table) {
            if ($schema->hasTable($table)) {
                $schema->drop($table);
            }
        }
    }
};
