<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Content Project Item: thời điểm Publishing Queue publish (SaaS-owned, không WP Schedule).
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_project_tasks')) {
            return;
        }

        if (Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'scheduled_publish_at')) {
            return;
        }

        Schema::connection($this->connection)->table('seo_project_tasks', function (Blueprint $table): void {
            $table->timestamp('scheduled_publish_at')
                ->nullable()
                ->after('target_date')
                ->index()
                ->comment('Publishing Queue due time — SaaS owns schedule, not WP future');
        });
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_project_tasks')) {
            return;
        }

        if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'scheduled_publish_at')) {
            return;
        }

        Schema::connection($this->connection)->table('seo_project_tasks', function (Blueprint $table): void {
            $table->dropColumn('scheduled_publish_at');
        });
    }
};
