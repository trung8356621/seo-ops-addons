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

        if ($schema->hasTable('seo_project_task_events')) {
            return;
        }

        $schema->create('seo_project_task_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedBigInteger('run_id')->nullable();

            $table->string('event', 64);
            $table->string('from_status', 50)->nullable();
            $table->string('to_status', 50)->nullable();

            $table->json('payload')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();

            $table->timestamp('created_at')->useCurrent();

            $table->foreign('task_id')
                ->references('id')
                ->on('seo_project_tasks')
                ->nullOnDelete();

            $table->foreign('run_id')
                ->references('id')
                ->on('seo_project_runs')
                ->nullOnDelete();

            // created_by: indexed only — user nằm connection default, không FK cross-DB.
            $table->index(['task_id', 'created_at'], 'seo_project_task_events_task_id_created_at_index');
            $table->index('run_id', 'seo_project_task_events_run_id_index');
            $table->index('event', 'seo_project_task_events_event_index');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_project_task_events');
    }
};
