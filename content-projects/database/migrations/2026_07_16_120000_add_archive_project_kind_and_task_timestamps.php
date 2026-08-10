<?php

declare(strict_types=1);

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
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

        if ($schema->hasTable('seo_projects') && ! $schema->hasColumn('seo_projects', 'kind')) {
            $schema->table('seo_projects', function (Blueprint $table): void {
                $table->string('kind', 32)
                    ->default('monthly')
                    ->after('status')
                    ->index()
                    ->comment('monthly | archive');
            });
        }

        if ($schema->hasTable('seo_project_tasks')) {
            $schema->table('seo_project_tasks', function (Blueprint $table) use ($schema): void {
                if (! $schema->hasColumn('seo_project_tasks', 'connected_at')) {
                    $table->timestamp('connected_at')->nullable()->after('status')->index();
                }
                if (! $schema->hasColumn('seo_project_tasks', 'completed_at')) {
                    $table->timestamp('completed_at')->nullable()->after('connected_at')->index();
                }
                if (! $schema->hasColumn('seo_project_tasks', 'archived_from_project_id')) {
                    $table->unsignedBigInteger('archived_from_project_id')
                        ->nullable()
                        ->after('completed_at')
                        ->index();
                }
            });
        }

        $this->backfillCompletedAt();
        $this->migrateLegacyArchiveBatches();
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('seo_project_tasks')) {
            $schema->table('seo_project_tasks', function (Blueprint $table) use ($schema): void {
                foreach (['archived_from_project_id', 'completed_at', 'connected_at'] as $column) {
                    if ($schema->hasColumn('seo_project_tasks', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if ($schema->hasTable('seo_projects') && $schema->hasColumn('seo_projects', 'kind')) {
            $schema->table('seo_projects', function (Blueprint $table): void {
                $table->dropColumn('kind');
            });
        }
    }

    private function backfillCompletedAt(): void
    {
        if (! Schema::connection($this->connection)->hasColumn('seo_project_tasks', 'completed_at')) {
            return;
        }

        DB::connection($this->connection)
            ->table('seo_project_tasks')
            ->where('status', SeoProjectTask::STATUS_COMPLETED)
            ->whereNull('completed_at')
            ->update([
                'completed_at' => DB::raw('COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)'),
            ]);
    }

    private function migrateLegacyArchiveBatches(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_project_archives') || ! $schema->hasTable('seo_project_archive_items')) {
            return;
        }

        $batches = DB::connection($this->connection)
            ->table('seo_project_archives')
            ->orderBy('id')
            ->get();

        if ($batches->isEmpty()) {
            return;
        }

        $projects = DB::connection($this->connection)
            ->table('seo_projects')
            ->whereIn('id', $batches->pluck('project_id')->unique()->filter()->all())
            ->get()
            ->keyBy('id');

        $archiveProjectsBySite = [];

        foreach ($batches as $batch) {
            $source = $projects->get($batch->project_id);
            if ($source === null) {
                continue;
            }

            $siteId = (int) ($source->site_id ?? 0);
            if ($siteId <= 0) {
                continue;
            }

            if (! isset($archiveProjectsBySite[$siteId])) {
                $existingArchiveId = (int) (DB::connection($this->connection)
                    ->table('seo_projects')
                    ->where('site_id', $siteId)
                    ->where('kind', 'archive')
                    ->orderBy('id')
                    ->value('id') ?? 0);

                if ($existingArchiveId <= 0) {
                    $existingArchiveId = (int) DB::connection($this->connection)
                        ->table('seo_projects')
                        ->insertGetId([
                            'name' => 'Lưu trữ',
                            'user_id' => (int) $source->user_id,
                            'site_id' => $siteId,
                            'month' => '2000-01-01',
                            'status' => SeoProject::STATUS_MANUAL,
                            'kind' => 'archive',
                            'total_tasks' => 0,
                            'description' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                }

                $archiveProjectsBySite[$siteId] = $existingArchiveId;
            }

            $archiveProjectId = $archiveProjectsBySite[$siteId];
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

                $existingTask = DB::connection($this->connection)
                    ->table('seo_project_tasks')
                    ->where('article_id', $articleId)
                    ->orderBy('id')
                    ->first();

                if ($existingTask !== null) {
                    // article_id unique toàn bảng — không insert trùng; chuyển task hiện có vào archive nếu chưa ở đó.
                    if ((int) $existingTask->project_id === $archiveProjectId) {
                        DB::connection($this->connection)
                            ->table('seo_project_tasks')
                            ->where('id', $existingTask->id)
                            ->update([
                                'connected_at' => $existingTask->connected_at ?? $connectedAt,
                                'completed_at' => $existingTask->completed_at ?? $connectedAt,
                                'archived_from_project_id' => $existingTask->archived_from_project_id
                                    ?? (int) $batch->project_id,
                                'status' => SeoProjectTask::STATUS_COMPLETED,
                                'updated_at' => now(),
                            ]);

                        continue;
                    }

                    DB::connection($this->connection)
                        ->table('seo_project_tasks')
                        ->where('id', $existingTask->id)
                        ->update([
                            'project_id' => $archiveProjectId,
                            'site_id' => $siteId,
                            'target_date' => '2000-01-01',
                            'status' => SeoProjectTask::STATUS_COMPLETED,
                            'connected_at' => $existingTask->connected_at ?? $connectedAt,
                            'completed_at' => $existingTask->completed_at ?? $connectedAt,
                            'archived_from_project_id' => (int) $batch->project_id,
                            'updated_at' => now(),
                        ]);

                    continue;
                }

                $article = DB::connection($this->connection)
                    ->table('articles')
                    ->where('id', $articleId)
                    ->first();

                $title = trim((string) ($article->title ?? ''));
                if ($title === '') {
                    $title = 'Article #'.$articleId;
                }

                DB::connection($this->connection)->table('seo_project_tasks')->insert([
                    'project_id' => $archiveProjectId,
                    'site_id' => $siteId,
                    'article_id' => $articleId,
                    'type' => SeoProjectTask::TYPE_NEW_TITLE,
                    'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
                    'source_content' => mb_substr($title, 0, 500),
                    'target_date' => '2000-01-01',
                    'status' => SeoProjectTask::STATUS_COMPLETED,
                    'connected_at' => $connectedAt,
                    'completed_at' => $connectedAt,
                    'archived_from_project_id' => (int) $batch->project_id,
                    'created_at' => $connectedAt,
                    'updated_at' => $connectedAt,
                ]);
            }
        }

        foreach ($archiveProjectsBySite as $archiveProjectId) {
            $count = (int) DB::connection($this->connection)
                ->table('seo_project_tasks')
                ->where('project_id', $archiveProjectId)
                ->count();

            DB::connection($this->connection)
                ->table('seo_projects')
                ->where('id', $archiveProjectId)
                ->update([
                    'total_tasks' => $count,
                    'updated_at' => now(),
                ]);
        }
    }
};
