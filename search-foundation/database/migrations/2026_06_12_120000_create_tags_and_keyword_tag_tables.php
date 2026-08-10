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
        Schema::connection($this->connection)->create('tags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();

            $table->unique(['site_id', 'slug']);
            $table->index(['site_id', 'name']);
        });

        Schema::connection($this->connection)->create('keyword_tag', function (Blueprint $table) {
            $table->foreignId('keyword_id')->constrained('keywords')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();

            $table->primary(['keyword_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('keyword_tag');
        Schema::connection($this->connection)->dropIfExists('tags');
    }
};
