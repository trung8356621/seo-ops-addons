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
            if (! Schema::connection($this->connection)->hasColumn('prompts', 'hook_key')) {
                $table->string('hook_key', 128)->nullable()->after('tools')->index();
            }
            if (! Schema::connection($this->connection)->hasColumn('prompts', 'hook_version')) {
                $table->unsignedInteger('hook_version')->nullable()->after('hook_key');
            }
            if (! Schema::connection($this->connection)->hasColumn('prompts', 'hook_settings')) {
                $table->json('hook_settings')->nullable()->after('hook_version');
            }
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('prompts', function (Blueprint $table): void {
            if (Schema::connection($this->connection)->hasColumn('prompts', 'hook_settings')) {
                $table->dropColumn('hook_settings');
            }
            if (Schema::connection($this->connection)->hasColumn('prompts', 'hook_version')) {
                $table->dropColumn('hook_version');
            }
            if (Schema::connection($this->connection)->hasColumn('prompts', 'hook_key')) {
                $table->dropColumn('hook_key');
            }
        });
    }
};
