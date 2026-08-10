<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Publishing Queue module ownership stamp (not archive).
 * Content Project working set = publishing_queued_at IS NULL.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_project_tasks')) {
            return;
        }

        Schema::connection($this->connection)->table('seo_project_tasks', function (Blueprint $table): void {
            if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'publishing_queued_at')) {
                $table->timestamp('publishing_queued_at')
                    ->nullable()
                    ->after('publish_published_at')
                    ->index()
                    ->comment('Handoff into Publishing Queue module; null = Content Project working set');
            }
            if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'publishing_queued_by')) {
                $table->unsignedInteger('publishing_queued_by')
                    ->nullable()
                    ->after('publishing_queued_at');
            }
        });

        // Backfill: existing schedule/publish evidence ⇒ already in Publishing Queue.
        try {
            DB::connection($this->connection)->statement("
                UPDATE seo_project_tasks
                SET publishing_queued_at = COALESCE(
                    publish_published_at,
                    last_publish_attempt_at,
                    scheduled_publish_at,
                    updated_at
                )
                WHERE publishing_queued_at IS NULL
                  AND archived_at IS NULL
                  AND (
                    publish_published_at IS NOT NULL
                    OR scheduled_publish_at IS NOT NULL
                    OR publish_queue_status IN ('waiting', 'processing', 'retrying', 'published', 'failed')
                  )
            ");
        } catch (\Throwable) {
            // Schema drift on older hosts — skip backfill.
        }

        // Future schedule = planning only; execution not started.
        try {
            DB::connection($this->connection)->statement("
                UPDATE seo_project_tasks
                SET publish_queue_status = 'none'
                WHERE scheduled_publish_at IS NOT NULL
                  AND scheduled_publish_at > NOW()
                  AND publish_queue_status = 'waiting'
            ");
        } catch (\Throwable) {
            // ignore
        }
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_project_tasks')) {
            return;
        }

        Schema::connection($this->connection)->table('seo_project_tasks', function (Blueprint $table): void {
            foreach (['publishing_queued_by', 'publishing_queued_at'] as $column) {
                if (Schema::connection($this->connection)->hasColumn('seo_project_tasks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
