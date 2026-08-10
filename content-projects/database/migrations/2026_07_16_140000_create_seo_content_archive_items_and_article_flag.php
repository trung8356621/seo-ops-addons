<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('articles') && ! $schema->hasColumn('articles', 'content_archived_at')) {
            $schema->table('articles', function (Blueprint $table) use ($schema): void {
                if ($schema->hasColumn('articles', 'reviewed_at')) {
                    $table->timestamp('content_archived_at')->nullable()->after('reviewed_at')->index();
                    $table->unsignedBigInteger('content_archived_by')->nullable()->after('content_archived_at')->index();
                } else {
                    $table->timestamp('content_archived_at')->nullable()->index();
                    $table->unsignedBigInteger('content_archived_by')->nullable()->index();
                }
            });
        }

        if (! $schema->hasTable('seo_content_archive_items')) {
            $schema->create('seo_content_archive_items', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id')->index();
                $table->unsignedBigInteger('article_id')->unique();
                $table->unsignedBigInteger('from_project_id')->nullable()->index();
                $table->unsignedBigInteger('archived_by')->nullable()->index();
                $table->timestamp('connected_at')->nullable()->index();
                $table->timestamp('completed_at')->nullable()->index();
                $table->timestamp('archived_at')->useCurrent()->index();
                $table->string('note', 500)->nullable();
                $table->string('source_content', 500)->nullable();
                $table->string('task_type', 32)->nullable();
                $table->timestamps();

                $table->index(['site_id', 'completed_at']);
                $table->index(['site_id', 'archived_at']);
            });
        }

        $this->migrateFromArchiveKindProjects();
        $this->migrateFromLegacyBatches();
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        $schema->dropIfExists('seo_content_archive_items');

        if ($schema->hasTable('articles')) {
            $schema->table('articles', function (Blueprint $table) use ($schema): void {
                foreach (['content_archived_by', 'content_archived_at'] as $column) {
                    if ($schema->hasColumn('articles', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function migrateFromArchiveKindProjects(): void
    {
        if (! Schema::connection($this->connection)->hasColumn('seo_projects', 'kind')) {
            return;
        }

        $archiveProjects = DB::connection($this->connection)
            ->table('seo_projects')
            ->where('kind', 'archive')
            ->orderBy('id')
            ->get();

        foreach ($archiveProjects as $project) {
            $siteId = (int) ($project->site_id ?? 0);
            if ($siteId <= 0) {
                continue;
            }

            $tasks = DB::connection($this->connection)
                ->table('seo_project_tasks')
                ->where('project_id', $project->id)
                ->whereNotNull('article_id')
                ->where('article_id', '>', 0)
                ->orderBy('id')
                ->get();

            foreach ($tasks as $task) {
                $this->upsertArchiveItem([
                    'site_id' => $siteId,
                    'article_id' => (int) $task->article_id,
                    'from_project_id' => (int) ($task->archived_from_project_id ?? 0) ?: null,
                    'archived_by' => (int) ($project->user_id ?? 0) ?: null,
                    'connected_at' => $task->connected_at ?? null,
                    'completed_at' => $task->completed_at ?? $task->updated_at ?? $task->created_at,
                    'archived_at' => $task->updated_at ?? $task->created_at ?? now(),
                    'source_content' => $task->source_content ?? null,
                    'task_type' => $task->type ?? null,
                ]);
            }

            DB::connection($this->connection)
                ->table('seo_project_tasks')
                ->where('project_id', $project->id)
                ->delete();

            DB::connection($this->connection)
                ->table('seo_projects')
                ->where('id', $project->id)
                ->delete();
        }
    }

    private function migrateFromLegacyBatches(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_project_archives') || ! $schema->hasTable('seo_project_archive_items')) {
            return;
        }

        $batches = DB::connection($this->connection)
            ->table('seo_project_archives')
            ->orderBy('id')
            ->get();

        $projects = DB::connection($this->connection)
            ->table('seo_projects')
            ->whereIn('id', $batches->pluck('project_id')->unique()->filter()->all())
            ->get()
            ->keyBy('id');

        foreach ($batches as $batch) {
            $source = $projects->get($batch->project_id);
            $siteId = (int) ($source->site_id ?? 0);
            if ($siteId <= 0) {
                continue;
            }

            $items = DB::connection($this->connection)
                ->table('seo_project_archive_items')
                ->where('seo_project_archive_id', $batch->id)
                ->orderBy('id')
                ->get();

            foreach ($items as $item) {
                $articleId = (int) ($item->article_id ?? 0);
                if ($articleId <= 0) {
                    continue;
                }

                $connectedAt = $item->created_at ?? $batch->created_at ?? now();

                $this->upsertArchiveItem([
                    'site_id' => $siteId,
                    'article_id' => $articleId,
                    'from_project_id' => (int) $batch->project_id,
                    'archived_by' => (int) ($batch->archived_by ?? 0) ?: null,
                    'connected_at' => $connectedAt,
                    'completed_at' => $connectedAt,
                    'archived_at' => $connectedAt,
                    'note' => $batch->note ?? null,
                    'source_content' => null,
                    'task_type' => null,
                ]);
            }
        }
    }

    /**
     * @param  array{
     *     site_id: int,
     *     article_id: int,
     *     from_project_id?: int|null,
     *     archived_by?: int|null,
     *     connected_at?: mixed,
     *     completed_at?: mixed,
     *     archived_at?: mixed,
     *     note?: string|null,
     *     source_content?: string|null,
     *     task_type?: string|null
     * }  $payload
     */
    private function upsertArchiveItem(array $payload): void
    {
        $articleId = (int) $payload['article_id'];
        if ($articleId <= 0) {
            return;
        }

        $existingId = (int) (DB::connection($this->connection)
            ->table('seo_content_archive_items')
            ->where('article_id', $articleId)
            ->value('id') ?? 0);

        $row = [
            'site_id' => (int) $payload['site_id'],
            'article_id' => $articleId,
            'from_project_id' => $payload['from_project_id'] ?? null,
            'archived_by' => $payload['archived_by'] ?? null,
            'connected_at' => $payload['connected_at'] ?? null,
            'completed_at' => $payload['completed_at'] ?? null,
            'archived_at' => $payload['archived_at'] ?? now(),
            'note' => $payload['note'] ?? null,
            'source_content' => isset($payload['source_content'])
                ? mb_substr((string) $payload['source_content'], 0, 500)
                : null,
            'task_type' => $payload['task_type'] ?? null,
            'updated_at' => now(),
        ];

        if ($existingId > 0) {
            DB::connection($this->connection)
                ->table('seo_content_archive_items')
                ->where('id', $existingId)
                ->update($row);
        } else {
            $row['created_at'] = now();
            DB::connection($this->connection)
                ->table('seo_content_archive_items')
                ->insert($row);
        }

        DB::connection($this->connection)
            ->table('articles')
            ->where('id', $articleId)
            ->whereNull('content_archived_at')
            ->update([
                'content_archived_at' => $row['archived_at'] ?? now(),
                'content_archived_by' => $row['archived_by'],
            ]);
    }
};
