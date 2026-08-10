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
        Schema::connection($this->connection)->table('seo_watermark_settings', function (Blueprint $table) {
            $table->boolean('auto_watermark')->default(false)->after('type');
            $table->json('design_config')->nullable()->after('opacity');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('seo_watermark_settings', function (Blueprint $table) {
            $table->dropColumn(['auto_watermark', 'design_config']);
        });
    }
};
