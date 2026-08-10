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
        Schema::connection($this->connection)->create('seo_media_processing_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('source', 20)->comment('wordpress | local');
            $table->unsignedBigInteger('media_ref_id')->comment('wp_attachment_id hoặc seo_media.id');
            $table->string('backup_path')->nullable();
            $table->string('original_url', 2048)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->boolean('is_watermarked')->default(false);
            $table->boolean('is_optimized')->default(false);
            $table->timestamp('watermarked_at')->nullable();
            $table->timestamp('optimized_at')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'source', 'media_ref_id'], 'seo_media_proc_hist_unique');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_media_processing_histories');
    }
};
