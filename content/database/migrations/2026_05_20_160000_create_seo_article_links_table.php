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
        Schema::connection($this->connection)->create('seo_article_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('article_id')->index();
            $table->enum('type', ['internal', 'external'])->index();
            $table->text('url')->comment('Đường dẫn href');
            $table->string('anchor_text', 500)->nullable()->comment('Văn bản chứa link');
            $table->boolean('is_nofollow')->default(false);
            $table->timestamps();

            $table->foreign('article_id')
                ->references('id')
                ->on('articles')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_article_links');
    }
};
