<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-project user decisions for Content Project Suggestions (dismiss/accept memory).
 * Does not cache computed candidates — SeoProjectTask remains planned truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('omi_seo_ai')->create('seo_content_project_suggestion_decisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->string('source_type', 64);
            $table->string('source_key', 191);
            $table->string('decision', 32);
            $table->unsignedBigInteger('article_id')->nullable()->index();
            $table->json('meta')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(
                ['project_id', 'source_type', 'source_key'],
                'scp_suggestion_decisions_project_source_unique',
            );
            $table->index(['project_id', 'decision'], 'scp_suggestion_decisions_project_decision_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('omi_seo_ai')->dropIfExists('seo_content_project_suggestion_decisions');
    }
};
