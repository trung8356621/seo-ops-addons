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
        Schema::connection($this->connection)->create('seo_generated_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->foreignId('article_id')
                ->nullable()
                ->constrained('articles')
                ->nullOnDelete();
            $table->string('slug', 255)->index();
            $table->text('url');
            $table->string('alt', 500)->nullable();
            $table->string('title', 500)->nullable();
            $table->string('source', 64)->default('ai');
            $table->unsignedBigInteger('wp_attachment_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_generated_images');
    }
};
