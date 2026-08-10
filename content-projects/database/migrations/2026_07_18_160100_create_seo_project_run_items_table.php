<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Model A: one row per logical operation (run_id + task_id + action).
 * Retry tăng attempt trên cùng row; audit chi tiết từng lần thuộc event/history Phase 3+.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('seo_project_run_items')) {
            return;
        }

        $schema->create('seo_project_run_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')
                ->constrained('seo_project_runs')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedBigInteger('article_id')->nullable();

            $table->string('action', 64);
            $table->string('status', 32);

            $table->unsignedInteger('attempt')->default(1);
            $table->char('idempotency_key', 64)->nullable();

            $table->text('message')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->longText('error_message')->nullable();

            $table->json('input_snapshot')->nullable();
            $table->json('output_snapshot')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('task_id')
                ->references('id')
                ->on('seo_project_tasks')
                ->nullOnDelete();

            // article_id: không FK — cùng convention seo_project_tasks.article_id.
            $table->index(['run_id', 'status'], 'seo_project_run_items_run_id_status_index');
            $table->index('task_id', 'seo_project_run_items_task_id_index');
            $table->index('article_id', 'seo_project_run_items_article_id_index');
            $table->index('action', 'seo_project_run_items_action_index');
            $table->unique('idempotency_key', 'seo_project_run_items_idempotency_key_unique');
            $table->unique(
                ['run_id', 'task_id', 'action'],
                'seo_project_run_items_run_task_action_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_project_run_items');
    }
};
