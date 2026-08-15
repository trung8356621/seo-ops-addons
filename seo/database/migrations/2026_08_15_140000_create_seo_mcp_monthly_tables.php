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
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('seo_mcp_periods')) {
            $schema->create('seo_mcp_periods', function (Blueprint $table): void {
                $table->id();
                $table->string('workspace_key', 64)->default('default')->index();
                $table->unsignedSmallInteger('year');
                $table->unsignedTinyInteger('month');
                $table->string('status', 16)->default('open')->index();
                $table->timestamp('opened_at')->nullable();
                $table->timestamp('finalized_at')->nullable();
                $table->unsignedBigInteger('finalized_by')->nullable();
                $table->boolean('manual_finalized')->default(false);
                $table->unsignedInteger('expected_sites')->default(0);
                $table->unsignedInteger('available_sites')->default(0);
                $table->timestamps();
                $table->unique(['workspace_key', 'year', 'month']);
            });
        }

        if (! $schema->hasTable('seo_mcp_source_snapshots')) {
            $schema->create('seo_mcp_source_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('period_id')->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('source', 32)->index();
                $table->string('schema_version', 32)->default('v1');
                $table->string('status', 16)->default('current')->index();
                $table->timestamp('generated_at')->nullable();
                $table->timestamp('source_updated_at')->nullable();
                $table->json('metrics_json')->nullable();
                $table->json('summary_json')->nullable();
                $table->json('context_json')->nullable();
                $table->string('content_hash', 64)->nullable()->index();
                $table->string('error_message', 500)->nullable();
                $table->timestamps();
                $table->unique(['period_id', 'site_id', 'source', 'schema_version'], 'seo_mcp_snap_period_site_source_ver');
            });
        }

        if (! $schema->hasTable('seo_mcp_reports')) {
            $schema->create('seo_mcp_reports', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('period_id')->index();
                $table->unsignedBigInteger('site_id')->nullable()->index();
                $table->unsignedInteger('revision')->default(1);
                $table->string('status', 16)->default('incomplete')->index();
                $table->unsignedBigInteger('site_snapshot_id')->nullable();
                $table->unsignedBigInteger('keyword_snapshot_id')->nullable();
                $table->json('overview_json')->nullable();
                $table->json('highlights_json')->nullable();
                $table->json('risks_json')->nullable();
                $table->json('opportunities_json')->nullable();
                $table->json('action_plan_json')->nullable();
                $table->json('ai_context_json')->nullable();
                $table->string('generation_status', 16)->default('idle');
                $table->string('current_source', 32)->nullable();
                $table->unsignedTinyInteger('completed_sources')->default(0);
                $table->unsignedTinyInteger('total_sources')->default(2);
                $table->timestamp('last_activity_at')->nullable();
                $table->timestamp('generated_at')->nullable();
                $table->timestamps();
                $table->unique(['period_id', 'site_id']);
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('seo_mcp_reports');
        $schema->dropIfExists('seo_mcp_source_snapshots');
        $schema->dropIfExists('seo_mcp_periods');
    }
};
