<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UNIQUE(project_id, source_key) — chỉ chạy sau repair sạch.
 * Migration validate và fail rõ nếu còn null/duplicate.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('seo_project_tasks')) {
            return;
        }

        if ($this->hasIndex('seo_project_tasks', 'seo_project_tasks_project_id_source_key_unique')) {
            return;
        }

        $nullCount = (int) DB::connection($this->connection)
            ->table('seo_project_tasks')
            ->whereNull('source_key')
            ->count();

        if ($nullCount > 0) {
            throw new \RuntimeException(
                "Cannot add unique(project_id, source_key): {$nullCount} task(s) still have NULL source_key. Run content-project:repair --apply first.",
            );
        }

        $dupes = DB::connection($this->connection)
            ->table('seo_project_tasks')
            ->selectRaw('project_id, source_key, COUNT(*) as c')
            ->whereNotNull('source_key')
            ->groupBy('project_id', 'source_key')
            ->havingRaw('COUNT(*) > 1')
            ->limit(5)
            ->get();

        if ($dupes->isNotEmpty()) {
            $sample = $dupes->map(static fn ($row): string => "project={$row->project_id} key=".substr((string) $row->source_key, 0, 12).'… count='.$row->c)
                ->implode('; ');
            throw new \RuntimeException(
                "Cannot add unique(project_id, source_key): duplicate groups remain. Sample: {$sample}. Run content-project:repair --apply first.",
            );
        }

        $schema->table('seo_project_tasks', function (Blueprint $table): void {
            $table->unique(['project_id', 'source_key'], 'seo_project_tasks_project_id_source_key_unique');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('seo_project_tasks')) {
            return;
        }

        if (! $this->hasIndex('seo_project_tasks', 'seo_project_tasks_project_id_source_key_unique')) {
            return;
        }

        $schema->table('seo_project_tasks', function (Blueprint $table): void {
            $table->dropUnique('seo_project_tasks_project_id_source_key_unique');
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $database = (string) DB::connection($this->connection)->getDatabaseName();
        $row = DB::connection($this->connection)->selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$database, $table, $indexName],
        );

        return (int) ($row->c ?? 0) > 0;
    }
};
