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
        if ($schema->hasTable('article_product_reviews')) {
            return;
        }

        $schema->create('article_product_reviews', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id')->index();
            $table->unsignedBigInteger('site_id')->index();
            $table->unsignedBigInteger('connection_id')->index();
            $table->unsignedBigInteger('wp_post_id')->nullable()->index();
            $table->bigInteger('wp_comment_id')->nullable()->index();

            $table->string('author_name', 191);
            $table->string('author_email', 191)->nullable();
            $table->text('content');
            $table->unsignedTinyInteger('rating')->nullable();
            $table->timestamp('review_date')->nullable();

            $table->string('source', 64)->default('ai_generated');
            $table->string('status', 32)->default('draft')->index();
            $table->unsignedInteger('publish_attempts')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->text('last_error_message')->nullable();

            $table->string('content_hash', 64)->index();
            $table->string('idempotency_key', 128);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'idempotency_key'], 'apr_connection_idempotency_unique');
            $table->index(['article_id', 'status'], 'apr_article_status_index');
        });
    }

    public function down(): void
    {
        Schema::connection('omi_seo_ai')->dropIfExists('article_product_reviews');
    }
};
