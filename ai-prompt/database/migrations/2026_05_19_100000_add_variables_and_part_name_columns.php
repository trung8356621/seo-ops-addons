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
        Schema::connection($this->connection)->table('prompt_parts', function (Blueprint $table) {
            if (! Schema::connection($this->connection)->hasColumn('prompt_parts', 'name')) {
                $table->string('name')->nullable()->after('role');
            }
        });

        Schema::connection($this->connection)->table('prompts', function (Blueprint $table) {
            if (! Schema::connection($this->connection)->hasColumn('prompts', 'variables')) {
                $table->json('variables')->nullable()->after('tools');
            }
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('prompt_parts', function (Blueprint $table) {
            if (Schema::connection($this->connection)->hasColumn('prompt_parts', 'name')) {
                $table->dropColumn('name');
            }
        });

        Schema::connection($this->connection)->table('prompts', function (Blueprint $table) {
            if (Schema::connection($this->connection)->hasColumn('prompts', 'variables')) {
                $table->dropColumn('variables');
            }
        });
    }
};
