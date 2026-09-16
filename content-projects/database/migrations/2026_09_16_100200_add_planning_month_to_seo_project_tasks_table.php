<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stamp each Draft/execution task with planning month (YYYY-MM via date first-of-month).
 * Shared Draft may hold multiple months; split scopes by this column + site_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('omi_seo_ai')->table('seo_project_tasks', function (Blueprint $table): void {
            if (! Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'planning_month')) {
                $table->date('planning_month')->nullable()->after('target_date')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::connection('omi_seo_ai')->table('seo_project_tasks', function (Blueprint $table): void {
            if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'planning_month')) {
                $table->dropColumn('planning_month');
            }
        });
    }
};
