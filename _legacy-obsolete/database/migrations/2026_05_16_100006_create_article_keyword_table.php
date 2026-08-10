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
        Schema::connection($this->connection)->create('article_keyword', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignId('keyword_id')->constrained('keywords')->cascadeOnDelete();
            $table->decimal('weight', 5, 4)->nullable();
            $table->timestamps();

            $table->unique(['article_id', 'keyword_id']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('article_keyword');
    }
};
