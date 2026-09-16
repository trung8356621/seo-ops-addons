<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-shot Available Ideas consumption tombstones (content-projects owned).
 * Identity: site_id + source_type + source_ref (e.g. vocabulary_suggest + keyword id).
 * Survives Draft keyword edits, task delete/cancel, and failed source cleanup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('omi_seo_ai')->create('seo_content_project_consumed_ideas', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('source_type', 64);
            /** Canonical source identity string (e.g. keyword id as decimal string). */
            $table->string('source_ref', 64);
            $table->unsignedBigInteger('source_keyword_id')->nullable()->index();
            $table->string('phrase_snapshot', 500)->nullable();
            $table->unsignedBigInteger('source_article_id')->nullable()->index();
            $table->string('vocabulary_group', 64)->nullable();
            $table->unsignedBigInteger('project_task_id')->nullable()->index();
            $table->timestamp('consumed_at')->useCurrent();
            $table->timestamps();

            $table->unique(['site_id', 'source_type', 'source_ref'], 'scp_consumed_ideas_identity_unique');
            $table->index(['site_id', 'source_type', 'source_keyword_id'], 'scp_consumed_ideas_site_source_kw_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('omi_seo_ai')->dropIfExists('seo_content_project_consumed_ideas');
    }
};
