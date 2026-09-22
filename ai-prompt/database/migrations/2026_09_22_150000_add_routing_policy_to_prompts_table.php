<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt-level routing policy override (normal | quick_free | free_only).
 * Null = Hook default via PromptRoutingPolicyResolver.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        Schema::connection($this->connection)->table('prompts', function (Blueprint $table): void {
            if (! Schema::connection($this->connection)->hasColumn('prompts', 'routing_policy')) {
                $table->string('routing_policy', 32)->nullable()->after('routing_profile_key');
            }
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('prompts', function (Blueprint $table): void {
            if (Schema::connection($this->connection)->hasColumn('prompts', 'routing_policy')) {
                $table->dropColumn('routing_policy');
            }
        });
    }
};
