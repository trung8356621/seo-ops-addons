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
            if (! Schema::connection($this->connection)->hasColumn('prompts', 'model_category')) {
                $table->string('model_category', 50)
                    ->nullable()
                    ->after('ai_connection_id')
                    ->comment('Nhãn đại diện: gemini_pro, gemini_flash, imagen_pro, claude_sonnet...');
            }
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('prompts', function (Blueprint $table) {
            if (Schema::connection($this->connection)->hasColumn('prompts', 'model_category')) {
                $table->dropColumn('model_category');
            }
        });
    }
};
