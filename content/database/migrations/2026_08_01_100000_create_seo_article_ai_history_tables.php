<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        Schema::connection($this->connection)->create('seo_article_ai_history_tombstones', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id')->index();
            $table->string('artifact_ref', 120);
            $table->unsignedBigInteger('prompt_result_id')->nullable();
            $table->string('artifact_type', 40)->nullable();
            $table->unsignedBigInteger('run_id')->nullable();
            $table->unsignedBigInteger('run_item_id')->nullable();
            $table->unsignedInteger('attempt')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamp('deleted_at')->useCurrent();
            $table->text('deletion_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['article_id', 'artifact_ref'], 'seo_article_ai_history_tombstones_article_ref_idx');
        });

        Schema::connection($this->connection)->create('seo_article_ai_history_applies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id')->index();
            $table->string('artifact_ref', 120)->index();
            $table->unsignedBigInteger('prompt_result_id')->nullable();
            $table->string('artifact_type', 40);
            $table->unsignedBigInteger('run_id')->nullable();
            $table->unsignedBigInteger('run_item_id')->nullable();
            $table->unsignedInteger('attempt')->nullable();
            $table->unsignedBigInteger('applied_by')->nullable();
            $table->timestamp('applied_at')->useCurrent();
            $table->string('target', 20);
            $table->string('apply_mode', 40)->default('manual_debug_apply');
            $table->tinyInteger('committed')->default(0);
            $table->json('provenance')->nullable();
            $table->timestamps();

            $table->index(['article_id', 'artifact_ref'], 'seo_article_ai_history_applies_article_ref_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_article_ai_history_applies');
        Schema::connection($this->connection)->dropIfExists('seo_article_ai_history_tombstones');
    }
};
