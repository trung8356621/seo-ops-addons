<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persists Topic grouping ANALYSIS proposals (not business membership).
 * Semantic vectors remain in seo-ops-semantic PostgreSQL only.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if ($schema->hasTable('seo_topic_grouping_runs')) {
            return;
        }

        $schema->create('seo_topic_grouping_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('provider', 64);
            $table->string('external_analysis_id', 64)->nullable();
            $table->string('input_hash', 64);
            $table->string('status', 32);
            $table->unsignedInteger('keyword_count')->default(0);
            $table->unsignedInteger('group_count')->default(0);
            $table->unsignedInteger('unassigned_count')->default(0);
            $table->unsignedInteger('low_confidence_count')->default(0);
            $table->string('model', 255)->nullable();
            $table->string('model_version', 128)->nullable();
            $table->string('algorithm', 128)->nullable();
            $table->json('proposal_payload')->nullable();
            $table->json('diagnostics')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status'], 'seo_topic_grouping_runs_site_status_idx');
            $table->index(['site_id', 'created_at'], 'seo_topic_grouping_runs_site_created_idx');
            $table->index(['input_hash'], 'seo_topic_grouping_runs_input_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_topic_grouping_runs');
    }
};
