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
        Schema::connection($this->connection)->create('seo_wp_media_edited_pending', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->unsignedBigInteger('wp_attachment_id');
            $table->unsignedBigInteger('seo_media_id')->index();
            $table->string('path')->comment('Đường dẫn file đã chỉnh sửa trên disk public');
            $table->string('original_wp_url', 2048)->nullable();
            $table->timestamp('edited_at');
            $table->timestamps();

            $table->unique(['site_id', 'wp_attachment_id'], 'seo_wp_media_edited_pending_unique');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_wp_media_edited_pending');
    }
};
