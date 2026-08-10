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
            if (! Schema::connection($this->connection)->hasColumn('prompts', 'prompt_data')) {
                $table->json('prompt_data')->nullable()->after('settings');
            }
            if (! Schema::connection($this->connection)->hasColumn('prompts', 'tools')) {
                $table->string('tools', 32)->nullable()->after('type');
            }
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('prompts', function (Blueprint $table) {
            if (Schema::connection($this->connection)->hasColumn('prompts', 'prompt_data')) {
                $table->dropColumn('prompt_data');
            }
            if (Schema::connection($this->connection)->hasColumn('prompts', 'tools')) {
                $table->dropColumn('tools');
            }
        });
    }
};
