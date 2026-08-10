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
        Schema::connection($this->connection)->create('seo_watermark_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id')->unique();
            $table->string('type', 16)->default('none');
            $table->string('text_content')->nullable();
            $table->string('text_color', 7)->default('#ffffff');
            $table->unsignedInteger('text_size')->default(20);
            $table->string('logo_path')->nullable();
            $table->unsignedTinyInteger('logo_width_pct')->default(20);
            $table->string('position', 20)->default('bottom-right');
            $table->decimal('opacity', 2, 1)->default(0.7);
            $table->timestamps();

            $table->index('site_id');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_watermark_settings');
    }
};
