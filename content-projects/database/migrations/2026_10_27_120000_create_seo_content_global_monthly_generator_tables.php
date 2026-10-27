<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global Monthly Generator — batch orchestration + singleton generation lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('omi_seo_ai');

        if (! $schema->hasTable('seo_content_global_batches')) {
            $schema->create('seo_content_global_batches', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->date('planning_month')->index();
                $table->string('status', 32)->default('pending')->index();
                $table->unsignedInteger('total_items')->default(0);
                $table->unsignedInteger('succeeded')->default(0);
                $table->unsignedInteger('failed')->default(0);
                $table->unsignedInteger('skipped')->default(0);
                $table->unsignedInteger('pending')->default(0);
                $table->unsignedBigInteger('current_project_id')->nullable()->index();
                $table->unsignedBigInteger('current_project_run_id')->nullable()->index();
                $table->unsignedBigInteger('current_batch_item_id')->nullable();
                $table->timestamp('stop_requested_at')->nullable();
                $table->unsignedBigInteger('stop_requested_by')->nullable();
                $table->string('ai_cost_policy', 32);
                $table->json('project_order')->nullable();
                $table->json('settings')->nullable();
                $table->string('lock_owner_token', 64)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['planning_month', 'status'], 'scgb_month_status_idx');
            });
        }

        if (! $schema->hasTable('seo_content_global_batch_items')) {
            $schema->create('seo_content_global_batch_items', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('batch_id')->index();
                $table->unsignedInteger('position')->default(0);
                $table->unsignedBigInteger('project_id')->index();
                $table->unsignedBigInteger('task_id');
                $table->string('item_type', 32)->nullable();
                $table->string('status', 32)->default('pending')->index();
                $table->string('skip_reason', 191)->nullable();
                $table->unsignedBigInteger('project_run_id')->nullable()->index();
                $table->unsignedBigInteger('run_item_id')->nullable();
                $table->unsignedInteger('attempt_count')->default(0);
                $table->text('error_message')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->unique(['batch_id', 'task_id'], 'scgbi_batch_task_unique');
                $table->index(['batch_id', 'position'], 'scgbi_batch_position_idx');
                $table->index(['batch_id', 'project_id', 'status'], 'scgbi_batch_project_status_idx');
            });
        }

        if (! $schema->hasTable('seo_content_generation_locks')) {
            $schema->create('seo_content_generation_locks', function (Blueprint $table): void {
                $table->id();
                $table->string('slot', 32)->default('global')->unique();
                $table->string('owner_type', 32);
                $table->unsignedBigInteger('owner_id');
                $table->string('owner_token', 64);
                $table->timestamp('acquired_at');
                $table->timestamp('heartbeat_at');
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['owner_type', 'owner_id'], 'scgl_owner_idx');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        $schema->dropIfExists('seo_content_global_batch_items');
        $schema->dropIfExists('seo_content_global_batches');
        $schema->dropIfExists('seo_content_generation_locks');
    }
};
