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
        Schema::connection($this->connection)->create('seo_media', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->foreignId('article_id')
                ->nullable()
                ->constrained('articles')
                ->nullOnDelete();
            $table->string('filename')->comment('Tên file gốc trên đĩa bao gồm cả đuôi mở rộng');
            $table->string('slug')->index()->comment('SEO Friendly slug của hình ảnh (cho phép đổi để rename file)');
            $table->string('path')->comment('Đường dẫn tương đối trên disk public (uploads/seo_media/...)');
            $table->string('url')->comment('URL tuyệt đối để hiển thị ảnh trên web');
            $table->string('source', 50)->default('clipboard')->comment('clipboard, ai_prompt');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_media');
    }
};
