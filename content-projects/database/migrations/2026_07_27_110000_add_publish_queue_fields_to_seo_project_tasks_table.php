<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Publishing Queue metadata trên Content Project Item.
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
            if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'publish_queue_status')) {
                $table->string('publish_queue_status', 32)
                    ->default('none')
                    ->after('scheduled_publish_at')
                    ->index()
                    ->comment('none|waiting|processing|retrying|published|failed|skipped|cancelled');
            }

            if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'publish_retry_count')) {
                $table->unsignedSmallInteger('publish_retry_count')
                    ->default(0)
                    ->after('publish_queue_status');
            }

            if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'last_publish_attempt_at')) {
                $table->timestamp('last_publish_attempt_at')
                    ->nullable()
                    ->after('publish_retry_count');
            }

            if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'last_publish_error')) {
                $table->text('last_publish_error')
                    ->nullable()
                    ->after('last_publish_attempt_at');
            }

            if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'publish_published_at')) {
                $table->timestamp('publish_published_at')
                    ->nullable()
                    ->after('last_publish_error')
                    ->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_project_tasks')) {
            return;
        }

        Schema::connection($this->connection)->table('seo_project_tasks', function (Blueprint $table): void {
            foreach ([
                'publish_published_at',
                'last_publish_error',
                'last_publish_attempt_at',
                'publish_retry_count',
                'publish_queue_status',
            ] as $column) {
                if (Schema::connection($this->connection)->hasColumn('seo_project_tasks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
