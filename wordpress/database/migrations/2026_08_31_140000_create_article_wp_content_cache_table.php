<?php

declare(strict_types=1);

/**
 * Temporary WordPress content cache for Article Editor.
 * NOT canonical Article body — TTL 7 days; body stays null for view-only opens.
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if ($schema->hasTable('article_wp_content_cache')) {
            return;
        }

        $schema->create('article_wp_content_cache', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id')->unique();
            $table->unsignedBigInteger('wp_post_id')->nullable()->index();
            $table->longText('rendered_html');
            $table->json('raw_content_json')->nullable();
            $table->string('wp_modified_gmt', 32)->nullable();
            $table->string('wp_content_hash', 64)->nullable()->index();
            $table->unsignedBigInteger('wp_revision_id')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('article_wp_content_cache');
    }
};
