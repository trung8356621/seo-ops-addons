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
        Schema::connection($this->connection)->create('article_meta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->string('meta_key', 191);
            $table->longText('meta_value')->nullable();
            $table->timestamps();

            $table->index(['article_id', 'meta_key']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('article_meta');
    }
};
