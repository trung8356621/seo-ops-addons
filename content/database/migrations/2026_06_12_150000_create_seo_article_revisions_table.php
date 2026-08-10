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
        Schema::connection($this->connection)->create('seo_article_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')
                ->constrained('articles')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('title', 500)->nullable();
            $table->longText('content')->nullable();
            $table->json('seo_meta')->nullable();
            $table->timestamps();

            $table->index(['article_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_article_revisions');
    }
};
