<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trace execution projects back to the Draft planning pool they were split from.
 * Month is reporting/execution period only — not a uniqueness key (no unique index added).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('omi_seo_ai')->table('seo_projects', function (Blueprint $table): void {
            if (! Schema::connection('omi_seo_ai')->hasColumn('seo_projects', 'source_draft_project_id')) {
                $table->unsignedBigInteger('source_draft_project_id')->nullable()->after('user_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::connection('omi_seo_ai')->table('seo_projects', function (Blueprint $table): void {
            if (Schema::connection('omi_seo_ai')->hasColumn('seo_projects', 'source_draft_project_id')) {
                $table->dropColumn('source_draft_project_id');
            }
        });
    }
};
