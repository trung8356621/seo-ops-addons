<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        Schema::connection($this->connection)->create('seo_article_headings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')
                ->constrained('articles')
                ->cascadeOnDelete();
            $table->string('heading_text', 255);
            $table->string('heading_slug', 255)->index();
            $table->unsignedTinyInteger('level')->comment('2 = H2, 3 = H3, 4 = H4');
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('seo_article_headings')
                ->cascadeOnDelete();
            $table->timestamps();
        });

        // FULLTEXT index cho semantic match (MySQL Natural Language Mode).
        DB::connection($this->connection)
            ->statement('ALTER TABLE seo_article_headings ADD FULLTEXT seo_article_headings_heading_text_fulltext (heading_text)');
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_article_headings');
    }
};
