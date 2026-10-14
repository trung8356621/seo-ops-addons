<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site semantic keyword-grouping execution state.
 *
 * Ready for Agent site selection only after a successful RefreshKeywordGroupsJob.
 * Group row counts are not a success signal.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if ($schema->hasTable('seo_keyword_group_semantic_states')) {
            return;
        }

        $schema->create('seo_keyword_group_semantic_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->unique('seo_kw_group_semantic_state_site_uq');
            $table->string('phase', 16);
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_keyword_group_semantic_states');
    }
};
