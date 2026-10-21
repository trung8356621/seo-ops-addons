<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase D/E — Tasks, schedules, triggers, jobs, attempts, events.
 */
return new class extends Migration
{
    protected $connection = 'omi_social_automation';

    public function up(): void
    {
        Schema::connection($this->connection)->create('sa_tasks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('task_uuid')->unique();
            $table->string('name', 255);
            $table->string('status', 32)->default('draft'); // draft|active|paused|archived
            $table->string('package_key', 128);
            $table->string('package_version', 64);
            $table->string('package_kind', 32); // widget|workflow
            $table->string('content_digest', 64)->nullable();
            $table->json('inputs')->nullable();
            $table->unsignedInteger('target_account_count')->default(1);
            $table->json('eligibility')->nullable();
            $table->json('execution_policy')->nullable();
            $table->timestamps();

            $table->index(['package_key', 'package_version']);
            $table->index('status');
        });

        Schema::connection($this->connection)->create('sa_schedules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('schedule_uuid')->unique();
            $table->foreignId('task_id')->constrained('sa_tasks')->cascadeOnDelete();
            $table->string('cron_expression', 64);
            $table->string('timezone', 64)->default('UTC');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('target_account_count')->nullable();
            $table->json('execution_policy')->nullable();
            $table->json('input_source')->nullable();
            $table->string('package_version_pin', 64)->nullable();
            $table->timestamp('last_fired_at')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'last_fired_at']);
        });

        Schema::connection($this->connection)->create('sa_schedule_triggers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('trigger_uuid')->unique();
            $table->foreignId('schedule_id')->constrained('sa_schedules')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('sa_tasks')->cascadeOnDelete();
            $table->string('idempotency_key', 64)->unique();
            $table->timestamp('fired_at');
            $table->string('outcome', 64)->default('pending');
            $table->unsignedInteger('target_count')->default(0);
            $table->unsignedInteger('dispatched_count')->default(0);
            $table->unsignedInteger('shortfall')->default(0);
            $table->string('skip_reason', 64)->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->index(['schedule_id', 'fired_at']);
            $table->index('outcome');
        });

        Schema::connection($this->connection)->create('sa_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('job_uuid')->unique();
            $table->foreignId('task_id')->constrained('sa_tasks')->cascadeOnDelete();
            $table->foreignId('schedule_trigger_id')->nullable()->constrained('sa_schedule_triggers')->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('sa_agents')->nullOnDelete();
            $table->foreignId('browser_session_id')->nullable()->constrained('sa_browser_sessions')->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('sa_accounts')->nullOnDelete();
            $table->string('package_key', 128);
            $table->string('package_version', 64);
            $table->string('content_digest', 64)->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('idempotency_key', 64)->unique();
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('dispatch_source', 32)->default('scheduler'); // scheduler|manual
            $table->timestamps();

            $table->index(['status', 'lease_expires_at']);
            $table->index(['agent_id', 'status']);
            $table->index(['browser_session_id', 'status']);
            $table->index('task_id');
        });

        Schema::connection($this->connection)->create('sa_job_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('attempt_uuid')->unique();
            $table->foreignId('job_id')->constrained('sa_jobs')->cascadeOnDelete();
            $table->unsignedInteger('attempt_no')->default(1);
            $table->string('status', 32)->default('started');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('result')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamps();

            $table->unique(['job_id', 'attempt_no']);
        });

        Schema::connection($this->connection)->create('sa_job_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_id')->constrained('sa_jobs')->cascadeOnDelete();
            $table->foreignId('attempt_id')->nullable()->constrained('sa_job_attempts')->nullOnDelete();
            $table->string('event_type', 64);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['job_id', 'occurred_at']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('sa_job_events');
        Schema::connection($this->connection)->dropIfExists('sa_job_attempts');
        Schema::connection($this->connection)->dropIfExists('sa_jobs');
        Schema::connection($this->connection)->dropIfExists('sa_schedule_triggers');
        Schema::connection($this->connection)->dropIfExists('sa_schedules');
        Schema::connection($this->connection)->dropIfExists('sa_tasks');
    }
};
