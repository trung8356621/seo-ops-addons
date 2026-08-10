<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mode 2 Parent/Child gallery executions — append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('omi_seo_ai');

        if (! $schema->hasTable('seo_product_gallery_executions')) {
            $schema->create('seo_product_gallery_executions', function (Blueprint $table): void {
                $table->id();
                $table->string('execution_id', 64)->unique();
                $table->unsignedBigInteger('article_id')->index();
                $table->unsignedBigInteger('site_id')->nullable()->index();
                $table->string('generation_mode', 32)->default('parent_child');
                $table->string('status', 32)->default('pending')->index();
                $table->unsignedBigInteger('parent_media_id')->nullable()->index();
                $table->json('planner_snapshot')->nullable();
                $table->json('global_context_snapshot')->nullable();
                $table->json('provider_snapshot')->nullable();
                $table->json('original_media_snapshot_ids')->nullable();
                $table->json('selection_snapshot')->nullable();
                $table->string('failure_reason', 500)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('seo_product_gallery_child_attempts')) {
            $schema->create('seo_product_gallery_child_attempts', function (Blueprint $table): void {
                $table->id();
                $table->string('execution_id', 64)->index();
                $table->unsignedBigInteger('parent_execution_id')->index();
                $table->unsignedBigInteger('parent_media_id')->nullable()->index();
                $table->unsignedInteger('slot_index');
                $table->string('shot_key', 64);
                $table->json('shot_definition_snapshot')->nullable();
                $table->unsignedInteger('attempt')->default(1);
                $table->string('status', 32)->default('pending')->index();
                $table->unsignedBigInteger('generated_media_id')->nullable()->index();
                $table->string('failure_reason', 500)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['execution_id', 'slot_index', 'attempt'], 'pg_child_exec_slot_attempt_idx');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        $schema->dropIfExists('seo_product_gallery_child_attempts');
        $schema->dropIfExists('seo_product_gallery_executions');
    }
};
