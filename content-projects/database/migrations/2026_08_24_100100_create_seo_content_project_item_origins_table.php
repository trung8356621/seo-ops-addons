<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traceability: Content Project item ← SEO Audit (or future suggestion sources).
 * Finding IDs are evidence only — business identity remains article_id + task.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('omi_seo_ai')->create('seo_content_project_item_origins', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id')->index();
            $table->unsignedBigInteger('project_task_id')->index();
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_article_id')->nullable()->index();
            $table->json('source_finding_ids')->nullable();
            $table->json('reason_codes')->nullable();
            $table->string('source_fingerprint', 64)->nullable()->index();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['project_task_id'], 'scp_item_origins_task_unique');
            $table->index(['project_id', 'source_type', 'source_article_id'], 'scp_item_origins_project_source_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('omi_seo_ai')->dropIfExists('seo_content_project_item_origins');
    }
};
