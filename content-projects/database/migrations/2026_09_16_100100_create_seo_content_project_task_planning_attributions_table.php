<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-task Site Planning attribution (content-projects owned).
 * project_task_id is unique — one planning unit across Draft → execution → article.
 *
 * Index names must stay ≤64 chars (MySQL limit) — table name is already long.
 * Idempotent: safe when a prior failed run left the table without a migrations row.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = 'omi_seo_ai';
        $tableName = 'seo_content_project_task_planning_attributions';

        if (! Schema::connection($connection)->hasTable($tableName)) {
            Schema::connection($connection)->create($tableName, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('project_task_id');
                $table->unsignedBigInteger('site_id');
                $table->unsignedBigInteger('planner_run_id')->nullable();
                /** Canonical YYYY-MM planning month for this task (immutable after stamp). */
                $table->string('planning_month', 7);
                $table->string('source_type', 64)->nullable();
                $table->unsignedBigInteger('source_keyword_id')->nullable();
                $table->string('cluster_ref', 191)->nullable();
                $table->string('cluster_name_snapshot', 255)->nullable();
                $table->json('dna_phrases')->nullable();
                /** attributed | unattributed */
                $table->string('attribution_status', 32)->default('unattributed');
                $table->timestamps();
            });
        }

        Schema::connection($connection)->table($tableName, function (Blueprint $table) use ($connection, $tableName): void {
            $this->ensureUnique($connection, $tableName, $table, ['project_task_id'], 'scp_tpa_task_unique');
            $this->ensureIndex($connection, $tableName, $table, ['site_id'], 'scp_tpa_site_idx');
            $this->ensureIndex($connection, $tableName, $table, ['planner_run_id'], 'scp_tpa_run_idx');
            $this->ensureIndex($connection, $tableName, $table, ['planning_month'], 'scp_tpa_month_idx');
            $this->ensureIndex($connection, $tableName, $table, ['source_keyword_id'], 'scp_tpa_src_kw_idx');
            $this->ensureIndex($connection, $tableName, $table, ['cluster_ref'], 'scp_tpa_cluster_idx');
            $this->ensureIndex($connection, $tableName, $table, ['attribution_status'], 'scp_tpa_status_idx');
            $this->ensureIndex($connection, $tableName, $table, ['site_id', 'planning_month'], 'scp_tpa_site_month_idx');
            $this->ensureIndex($connection, $tableName, $table, ['site_id', 'planning_month', 'cluster_ref'], 'scp_tpa_site_month_cluster_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('omi_seo_ai')->dropIfExists('seo_content_project_task_planning_attributions');
    }

    /**
     * @param  list<string>  $columns
     */
    private function ensureUnique(string $connection, string $tableName, Blueprint $table, array $columns, string $name): void
    {
        if ($this->indexExists($connection, $tableName, $name)) {
            return;
        }
        $table->unique($columns, $name);
    }

    /**
     * @param  list<string>  $columns
     */
    private function ensureIndex(string $connection, string $tableName, Blueprint $table, array $columns, string $name): void
    {
        if ($this->indexExists($connection, $tableName, $name)) {
            return;
        }
        $table->index($columns, $name);
    }

    private function indexExists(string $connection, string $tableName, string $indexName): bool
    {
        $database = Schema::connection($connection)->getConnection()->getDatabaseName();
        $row = Schema::connection($connection)->getConnection()->selectOne(
            'select 1 as ok from information_schema.statistics where table_schema = ? and table_name = ? and index_name = ? limit 1',
            [$database, $tableName, $indexName],
        );

        return $row !== null;
    }
};
