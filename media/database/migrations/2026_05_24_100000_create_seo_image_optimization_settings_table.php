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
        Schema::connection($this->connection)->create('seo_image_optimization_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id')->nullable()->unique()->index()->comment('Null nếu là cấu hình mặc định');
            $table->boolean('auto_convert_webp')->default(true);
            $table->unsignedTinyInteger('quality')->default(80);
            $table->boolean('limit_dimensions')->default(true);
            $table->unsignedInteger('max_width')->default(1200);
            $table->unsignedInteger('max_height')->default(1200);
            $table->boolean('clean_filename')->default(true);
            $table->boolean('auto_alt_tag')->default(true);
            $table->string('alt_tag_pattern')->default('{post_title} - {focus_keyword}');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_image_optimization_settings');
    }
};
