<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WordPress-owned external link/sync state per article.
 * articles.wp_* / last_synced_at remain compatibility projections until drop.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('wordpress_article_links')) {
            $schema->create('wordpress_article_links', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('article_id')->unique();
                $table->unsignedBigInteger('site_id')->nullable()->index();
                $table->unsignedBigInteger('wp_post_id')->nullable()->index();
                $table->string('sync_status', 32)->default('idle')->index();
                $table->unsignedBigInteger('sync_job_id')->nullable()->index();
                $table->timestamp('last_synced_at')->nullable()->index();
                $table->timestamp('external_modified_at')->nullable();
                $table->timestamps();

                $table->foreign('article_id')
                    ->references('id')
                    ->on('articles')
                    ->cascadeOnDelete();
            });
        }

        if (! $schema->hasTable('articles') || ! $schema->hasTable('wordpress_article_links')) {
            return;
        }

        if ((int) DB::connection($this->connection)->table('wordpress_article_links')->count() > 0) {
            return;
        }

        DB::connection($this->connection)->statement(
            'INSERT INTO wordpress_article_links
                (article_id, site_id, wp_post_id, sync_status, sync_job_id, last_synced_at, created_at, updated_at)
             SELECT id, site_id, wp_post_id, wp_sync_status, wp_sync_job_id, last_synced_at, created_at, updated_at
             FROM articles'
        );
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wordpress_article_links');
    }
};
