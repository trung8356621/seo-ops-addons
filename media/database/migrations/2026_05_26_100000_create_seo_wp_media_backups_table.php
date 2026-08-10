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
        Schema::connection($this->connection)->create('seo_wp_media_backups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->unsignedBigInteger('wp_attachment_id')->index();
            $table->string('backup_path')->comment('Đường dẫn file gốc trên disk public Laravel');
            $table->string('original_url', 2048)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'wp_attachment_id']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_wp_media_backups');
    }
};
