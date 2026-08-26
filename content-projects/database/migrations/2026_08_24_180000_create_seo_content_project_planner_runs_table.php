<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic planner run history (SEO Audit Fill, AI New Content, …).
 * Immutable configuration snapshots — not rejection memory.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        if ($schema->hasTable('seo_content_project_planner_runs')) {
            return;
        }

        $schema->create('seo_content_project_planner_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id')->index();
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->string('source_type', 64);
            $table->unsignedInteger('requested_quantity')->default(0);
            $table->json('configuration_snapshot')->nullable();
            $table->json('result_summary')->nullable();
            $table->unsignedBigInteger('prompt_result_id')->nullable()->index();
            $table->string('execution_ref', 191)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'source_type', 'created_at'], 'scp_planner_runs_project_source_created_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('omi_seo_ai')->dropIfExists('seo_content_project_planner_runs');
    }
};
