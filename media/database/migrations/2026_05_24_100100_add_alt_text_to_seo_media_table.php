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
        Schema::connection($this->connection)->table('seo_media', function (Blueprint $table) {
            $table->string('alt_text')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('seo_media', function (Blueprint $table) {
            $table->dropColumn('alt_text');
        });
    }
};
