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
        Schema::connection($this->connection)->table('articles', function (Blueprint $table) {
            $table->unsignedInteger('internal_link_count')->default(0)->after('seo_score');
            $table->unsignedInteger('external_link_count')->default(0)->after('internal_link_count');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('articles', function (Blueprint $table) {
            $table->dropColumn(['internal_link_count', 'external_link_count']);
        });
    }
};
