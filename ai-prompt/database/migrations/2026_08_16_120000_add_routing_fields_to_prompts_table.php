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
        Schema::connection($this->connection)->table('prompts', function (Blueprint $table): void {
            if (! Schema::connection($this->connection)->hasColumn('prompts', 'routing_mode')) {
                $table->string('routing_mode', 16)->default('auto')->after('ai_connection_id');
            }
            if (! Schema::connection($this->connection)->hasColumn('prompts', 'routing_profile_key')) {
                $table->string('routing_profile_key', 64)->nullable()->after('routing_mode');
            }
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('prompts', function (Blueprint $table): void {
            if (Schema::connection($this->connection)->hasColumn('prompts', 'routing_profile_key')) {
                $table->dropColumn('routing_profile_key');
            }
            if (Schema::connection($this->connection)->hasColumn('prompts', 'routing_mode')) {
                $table->dropColumn('routing_mode');
            }
        });
    }
};
