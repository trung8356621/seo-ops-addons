<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        if (! $schema->hasTable('seo_content_project_item_origins')) {
            return;
        }

        if ($schema->hasColumn('seo_content_project_item_origins', 'planner_run_id')) {
            return;
        }

        $schema->table('seo_content_project_item_origins', function (Blueprint $table): void {
            $table->unsignedBigInteger('planner_run_id')->nullable()->after('project_id')->index();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        if (! $schema->hasTable('seo_content_project_item_origins')) {
            return;
        }

        if (! $schema->hasColumn('seo_content_project_item_origins', 'planner_run_id')) {
            return;
        }

        $schema->table('seo_content_project_item_origins', function (Blueprint $table): void {
            $table->dropColumn('planner_run_id');
        });
    }
};
