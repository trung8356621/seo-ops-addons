<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('omi_seo_ai')->table('seo_content_project_item_origins', function (Blueprint $table): void {
            $table->unsignedBigInteger('planner_run_id')->nullable()->after('project_id')->index();
        });
    }

    public function down(): void
    {
        Schema::connection('omi_seo_ai')->table('seo_content_project_item_origins', function (Blueprint $table): void {
            $table->dropColumn('planner_run_id');
        });
    }
};
