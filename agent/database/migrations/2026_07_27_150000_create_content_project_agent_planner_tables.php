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

        if (! $schema->hasTable('seo_content_project_agent_plans')) {
            $schema->create('seo_content_project_agent_plans', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('site_id')->nullable()->index();
                $table->string('session_ref', 64)->nullable()->index();
                $table->string('name', 255)->nullable();
                $table->text('objective')->nullable();
                $table->string('status', 32)->default('draft')->index();
                $table->string('trigger_type', 32)->default('manual')->index();
                $table->string('policy_ref', 64)->nullable()->index();
                $table->string('project_ref', 64)->nullable()->index();
                $table->unsignedInteger('current_step_index')->default(0);
                $table->unsignedInteger('total_steps')->default(0);
                $table->json('input_payload')->nullable();
                $table->json('resolved_context')->nullable();
                $table->json('summary')->nullable();
                $table->boolean('requires_user_confirmation')->default(false);
                $table->string('confirmation_status', 32)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->string('created_by_type', 32)->nullable();
                $table->string('created_by_ref', 128)->nullable();
                $table->unsignedInteger('plan_version')->default(1);
                $table->string('previous_plan_ref', 64)->nullable();
                $table->text('replan_reason')->nullable();
                $table->unsignedInteger('replan_count')->default(0);
                $table->string('automation_level', 32)->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('seo_content_project_agent_plan_steps')) {
            $schema->create('seo_content_project_agent_plan_steps', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('plan_id')->index();
                $table->unsignedInteger('position')->default(0);
                $table->string('capability', 128)->nullable();
                $table->string('intent', 255)->nullable();
                $table->json('input_payload')->nullable();
                $table->json('resolved_input')->nullable();
                $table->string('status', 32)->default('pending')->index();
                $table->string('operation_ref', 64)->nullable();
                $table->string('confirmation_token_ref', 128)->nullable();
                $table->string('result_code', 64)->nullable();
                $table->text('result_summary')->nullable();
                $table->text('error_summary')->nullable();
                $table->unsignedInteger('attempt_count')->default(0);
                $table->unsignedInteger('max_attempts')->default(4);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->json('depends_on_step_refs')->nullable();
                $table->json('condition_payload')->nullable();
                $table->string('step_type', 32)->default('capability');
                $table->string('idempotency_key', 128)->nullable()->unique();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('seo_content_project_automation_policies')) {
            $schema->create('seo_content_project_automation_policies', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('site_id')->nullable()->index();
                $table->string('project_type', 64)->nullable();
                $table->string('pipeline', 64)->nullable();
                $table->string('name', 255);
                $table->boolean('enabled')->default(true)->index();
                $table->string('automation_level', 32)->default('manual');
                $table->json('allowed_capabilities')->nullable();
                $table->json('blocked_capabilities')->nullable();
                $table->boolean('auto_generate')->default(false);
                $table->boolean('auto_review')->default(false);
                $table->boolean('auto_approve')->default(false);
                $table->boolean('auto_schedule')->default(false);
                $table->boolean('auto_publish')->default(false);
                $table->json('require_confirmation_for')->nullable();
                $table->unsignedInteger('max_items_per_plan')->nullable();
                $table->unsignedInteger('max_plans_per_day')->nullable();
                $table->json('allowed_publish_windows')->nullable();
                $table->string('timezone', 64)->default('Asia/Ho_Chi_Minh');
                $table->boolean('auto_retry_transient')->default(true);
                $table->unsignedInteger('auto_retry_max')->default(4);
                $table->boolean('pause_on_failure')->default(true);
                $table->boolean('pause_on_approval_reject')->default(true);
                $table->unsignedInteger('daily_action_budget')->nullable();
                $table->unsignedInteger('daily_item_budget')->nullable();
                $table->unsignedInteger('daily_cost_budget_cents')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('seo_content_project_agent_approvals')) {
            $schema->create('seo_content_project_agent_approvals', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->string('plan_ref', 64)->index();
                $table->string('step_ref', 64)->nullable()->index();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('site_id')->nullable()->index();
                $table->string('actor_ref', 128);
                $table->string('action', 128);
                $table->text('summary')->nullable();
                $table->string('risk_level', 32)->default('write');
                $table->json('preview_payload')->nullable();
                $table->string('status', 32)->default('pending')->index();
                $table->string('state_fingerprint', 64);
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('rejected_at')->nullable();
                $table->string('approved_by_ref', 128)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_content_project_agent_approvals');
        Schema::connection($this->connection)->dropIfExists('seo_content_project_automation_policies');
        Schema::connection($this->connection)->dropIfExists('seo_content_project_agent_plan_steps');
        Schema::connection($this->connection)->dropIfExists('seo_content_project_agent_plans');
    }
};
