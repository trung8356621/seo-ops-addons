<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lightweight Draft-only planning review marker (not Article / CM review lifecycle).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        if (! $schema->hasTable('seo_project_tasks')) {
            return;
        }

        $schema->table('seo_project_tasks', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('seo_project_tasks', 'planning_reviewed_at')) {
                $table->timestamp('planning_reviewed_at')->nullable()->after('content_manager_reviewed_by');
            }
            if (! $schema->hasColumn('seo_project_tasks', 'planning_reviewed_by')) {
                $table->unsignedBigInteger('planning_reviewed_by')->nullable()->after('planning_reviewed_at');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        if (! $schema->hasTable('seo_project_tasks')) {
            return;
        }

        $schema->table('seo_project_tasks', function (Blueprint $table) use ($schema): void {
            if ($schema->hasColumn('seo_project_tasks', 'planning_reviewed_by')) {
                $table->dropColumn('planning_reviewed_by');
            }
            if ($schema->hasColumn('seo_project_tasks', 'planning_reviewed_at')) {
                $table->dropColumn('planning_reviewed_at');
            }
        });
    }
};
