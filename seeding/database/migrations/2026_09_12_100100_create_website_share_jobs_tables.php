<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website Share — separate from Topic Comment seeding.
 */
return new class extends Migration
{
    protected $connection = 'omi_seeding';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('website_share_jobs')) {
            $schema->create('website_share_jobs', function (Blueprint $table): void {
                $table->id();
                $table->string('installation_id', 64)->index();
                $table->unsignedBigInteger('article_id')->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('index_generation', 64)->index();
                $table->string('source_type', 32)->default('seo_index');
                $table->string('status', 32)->default('scheduled')->index();
                $table->string('title', 512)->nullable();
                $table->text('article_url')->nullable();
                $table->string('domain', 255)->nullable();
                $table->text('thumbnail_url')->nullable();
                $table->timestamp('indexed_at')->nullable()->index();
                $table->timestamp('eligible_at')->nullable()->index();
                $table->longText('share_content')->nullable();
                $table->timestamp('content_generated_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['installation_id', 'article_id', 'index_generation'],
                    'wsj_install_article_gen_uq'
                );
                $table->index(['installation_id', 'status', 'eligible_at'], 'wsj_install_status_eligible_idx');
            });
        }

        if (! $schema->hasTable('website_share_targets')) {
            $schema->create('website_share_targets', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('job_id')->index();
                $table->string('social', 32)->index();
                $table->unsignedInteger('target_count')->default(1);
                $table->unsignedInteger('completed_count')->default(0);
                $table->timestamps();

                $table->unique(['job_id', 'social'], 'wst_job_social_uq');
            });
        }

        if (! $schema->hasTable('website_share_reports')) {
            $schema->create('website_share_reports', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('job_id')->index();
                $table->unsignedBigInteger('target_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('user_display_name', 191)->nullable();
                $table->string('social', 32)->index();
                $table->longText('share_text')->nullable();
                $table->string('proof_path', 512)->nullable();
                $table->string('proof_mime', 128)->nullable();
                $table->json('proof_meta')->nullable();
                $table->timestamp('reported_at')->index();
                $table->timestamps();

                $table->index(['job_id', 'user_id', 'social']);
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('website_share_reports');
        $schema->dropIfExists('website_share_targets');
        $schema->dropIfExists('website_share_jobs');
    }
};
