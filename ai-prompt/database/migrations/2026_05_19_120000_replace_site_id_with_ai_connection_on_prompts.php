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
        Schema::connection($this->connection)->table('prompts', function (Blueprint $table) {
            if (Schema::connection($this->connection)->hasColumn('prompts', 'site_id')) {
                $table->dropColumn('site_id');
            }
        });

        Schema::connection($this->connection)->table('prompts', function (Blueprint $table) {
            if (! Schema::connection($this->connection)->hasColumn('prompts', 'ai_connection_id')) {
                $table->unsignedBigInteger('ai_connection_id')->nullable()->index()->after('user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('prompts', function (Blueprint $table) {
            if (Schema::connection($this->connection)->hasColumn('prompts', 'ai_connection_id')) {
                $table->dropColumn('ai_connection_id');
            }
        });

        Schema::connection($this->connection)->table('prompts', function (Blueprint $table) {
            if (! Schema::connection($this->connection)->hasColumn('prompts', 'site_id')) {
                $table->unsignedBigInteger('site_id')->nullable()->index()->after('user_id');
            }
        });
    }
};
