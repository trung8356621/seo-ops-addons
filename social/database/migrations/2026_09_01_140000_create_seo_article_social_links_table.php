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
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('seo_article_social_links')) {
            return;
        }

        $schema->create('seo_article_social_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id')->index();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('url', 2048);
            $table->char('url_hash', 64);
            $table->string('domain', 191)->index();
            $table->string('source', 32)->index();
            $table->string('integration_key', 100)->nullable()->index();
            $table->string('external_ref', 191)->nullable();
            $table->timestamp('recorded_at')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['article_id', 'url_hash'], 'seo_article_social_links_unique');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_article_social_links');
    }
};
