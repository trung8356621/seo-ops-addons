<?php

declare(strict_types=1);

// Phase 5 — Agent Workspace scheduled automations (omi_seo_ai).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('seo_agent_automations')) {
            $schema->create('seo_agent_automations', function (Blueprint $table): void {
                $table->id();
                $table->string('hash_id', 64)->unique();
                $table->string('connection_hash', 64)->nullable()->index();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('site_ref', 128)->index();
                $table->unsignedBigInteger('owner_user_id')->index();
                $table->string('name', 255);
                $table->text('description')->nullable();
                $table->string('type', 64)->index();
                $table->string('scope_type', 32)->default('site');
                $table->string('scope_ref', 128)->nullable();
                $table->string('status', 32)->default('active')->index();
                $table->boolean('enabled')->default(true)->index();
                $table->unsignedInteger('version')->default(1);
                $table->string('definition_hash', 64)->index();
                $table->json('trigger_json');
                $table->json('workflow_json');
                $table->json('condition_json')->nullable();
                $table->json('notification_json')->nullable();
                $table->json('policy_json')->nullable();
                $table->string('timezone', 64)->default('UTC');
                $table->timestamp('next_run_at')->nullable()->index();
                $table->timestamp('last_run_at')->nullable();
                $table->string('last_run_status', 32)->nullable();
                $table->unsignedBigInteger('conversation_id')->nullable()->index();
                $table->timestamp('paused_at')->nullable();
                $table->string('pause_reason', 64)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['connection_hash', 'status'], 'seo_agent_automations_conn_status_index');
                $table->index(['owner_user_id', 'status'], 'seo_agent_automations_owner_status_index');
                $table->index(['site_id', 'enabled', 'next_run_at'], 'seo_agent_automations_due_index');
            });
        }

        if (! $schema->hasTable('seo_agent_automation_runs')) {
            $schema->create('seo_agent_automation_runs', function (Blueprint $table): void {
                $table->id();
                $table->string('hash_id', 64)->unique();
                $table->unsignedBigInteger('automation_id')->index();
                $table->string('occurrence_key', 128);
                $table->string('idempotency_key', 128)->unique();
                $table->string('status', 32)->index();
                $table->string('skip_reason', 64)->nullable();
                $table->unsignedSmallInteger('attempt')->default(1);
                $table->unsignedInteger('definition_version');
                $table->string('definition_hash', 64);
                $table->timestamp('scheduled_at')->nullable()->index();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->json('step_results')->nullable();
                $table->json('condition_result')->nullable();
                $table->json('result_summary')->nullable();
                $table->json('error_payload')->nullable();
                $table->string('error_category', 64)->nullable();
                $table->string('execution_ref')->nullable();
                $table->string('planning_request_id')->nullable();
                $table->string('notification_status', 32)->nullable();
                $table->string('trigger_source', 32)->default('schedule');
                $table->timestamps();

                $table->unique(['automation_id', 'occurrence_key'], 'seo_agent_automation_runs_occurrence_unique');
                $table->index(['automation_id', 'created_at'], 'seo_agent_automation_runs_hist_index');
            });
        }

        if (! $schema->hasTable('seo_agent_automation_approvals')) {
            $schema->create('seo_agent_automation_approvals', function (Blueprint $table): void {
                $table->id();
                $table->string('hash_id', 64)->unique();
                $table->unsignedBigInteger('automation_id')->index();
                $table->unsignedBigInteger('run_id')->index();
                $table->unsignedBigInteger('actor_user_id')->index();
                $table->string('site_ref', 128);
                $table->unsignedInteger('definition_version');
                $table->string('definition_hash', 64);
                $table->string('token_hash', 64)->index();
                $table->string('status', 32)->default('pending')->index();
                $table->json('preview_payload')->nullable();
                $table->string('execution_ref')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamp('resolved_at')->nullable();
                $table->unsignedBigInteger('resolved_by')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('seo_agent_automation_states')) {
            $schema->create('seo_agent_automation_states', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('automation_id');
                $table->string('state_key', 128);
                $table->string('fingerprint', 64)->nullable();
                $table->json('payload')->nullable();
                $table->timestamp('observed_at')->nullable();
                $table->timestamps();

                $table->unique(['automation_id', 'state_key'], 'seo_agent_automation_states_unique');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        foreach ([
            'seo_agent_automation_states',
            'seo_agent_automation_approvals',
            'seo_agent_automation_runs',
            'seo_agent_automations',
        ] as $table) {
            if ($schema->hasTable($table)) {
                $schema->drop($table);
            }
        }
    }
};
