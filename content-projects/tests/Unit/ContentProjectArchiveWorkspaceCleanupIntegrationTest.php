<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Models\SeoPromptResultLink;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoArticleRevision;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchive;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchiveItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ArchiveContentProjectService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArticleMembership;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\ContentProjectAiWorkspaceDestroyer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\ContentProjectWorkspaceArticleOwnershipGuard;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DB-backed ownership/GC regressions for archived Clean workspace.
 * Requires SEO_TEST_USE_MYSQL=true + migrated omi_seo_ai.
 */
final class ContentProjectArchiveWorkspaceCleanupIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    protected $connectionsToTransact = ['omi_seo_ai'];

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var(env('SEO_TEST_USE_MYSQL', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set SEO_TEST_USE_MYSQL=true to run against local omi_seo_ai.');
        }

        foreach ([
            'seo_projects',
            'seo_project_tasks',
            'seo_project_archives',
            'seo_project_archive_items',
            'articles',
            'seo_article_revisions',
            'seo_project_runs',
            'seo_project_run_items',
        ] as $table) {
            if (! Schema::connection('omi_seo_ai')->hasTable($table)) {
                $this->markTestSkipped("Missing table on omi_seo_ai: {$table}");
            }
        }
    }

    public function test_ownership_guard_skips_active_and_ignores_archived_or_soft_deleted_tasks(): void
    {
        $siteId = 93001;
        $safe = $this->createArticle($siteId, 'Safe orphan');
        $reused = $this->createArticle($siteId, 'Reused active');
        $historicalOnly = $this->createArticle($siteId, 'Historical archived task');
        $softDeletedOnly = $this->createArticle($siteId, 'Soft deleted task');
        $itemArchivedArticle = $this->createArticle($siteId, 'Item-archived task');

        $archivedProject = $this->createProject(userId: 93011, siteId: $siteId, name: 'Archived A');
        $archivedProject->forceFill(['archived_at' => now(), 'archived_by' => 1])->saveQuietly();

        // Historical leftover on archived project must NOT count as active ownership.
        $this->createTask($archivedProject, (int) $historicalOnly->id, SeoProjectTask::STATUS_COMPLETED);

        $activeProject = $this->createProject(userId: 93012, siteId: $siteId, name: 'Active B');
        $this->createTask($activeProject, (int) $reused->id, SeoProjectTask::STATUS_COMPLETED);

        $softTask = $this->createTask($activeProject, (int) $softDeletedOnly->id, SeoProjectTask::STATUS_PENDING);
        $softTask->delete();

        $itemArchivedTask = $this->createTask($activeProject, (int) $itemArchivedArticle->id, SeoProjectTask::STATUS_COMPLETED);
        $itemArchivedTask->forceFill(['archived_at' => now()])->saveQuietly();

        $guard = app(ContentProjectWorkspaceArticleOwnershipGuard::class);
        $partition = $guard->partitionHistoricalArticles([
            (int) $safe->id,
            (int) $reused->id,
            (int) $historicalOnly->id,
            (int) $softDeletedOnly->id,
            (int) $itemArchivedArticle->id,
        ]);

        self::assertContains((int) $reused->id, $partition['skipped_reused']);
        self::assertContains((int) $safe->id, $partition['safe']);
        self::assertContains((int) $historicalOnly->id, $partition['safe']);
        self::assertContains((int) $softDeletedOnly->id, $partition['safe']);
        self::assertContains((int) $itemArchivedArticle->id, $partition['safe']);
        self::assertNotContains((int) $reused->id, $partition['safe']);

        $membership = app(ContentProjectArticleMembership::class);
        self::assertTrue($membership->belongsToActiveContentProject((int) $reused->id));
        self::assertFalse($membership->belongsToActiveContentProject((int) $historicalOnly->id));
        self::assertFalse($membership->belongsToActiveContentProject((int) $softDeletedOnly->id));
        self::assertFalse($membership->belongsToActiveContentProject((int) $itemArchivedArticle->id));
    }

    public function test_manual_cleanup_skips_reused_articles_and_does_not_reset_tasks(): void
    {
        $siteId = 93002;
        $safeArticle = $this->createArticle($siteId, 'GC safe');
        $reusedArticle = $this->createArticle($siteId, 'GC reused');

        $archivedProject = $this->createProject(userId: 93021, siteId: $siteId, name: 'Archived cleanup');
        $archivedProject->forceFill(['archived_at' => now(), 'archived_by' => 1])->saveQuietly();

        // Post-archive shape: tasks detached from articles, ready for fresh flow.
        $taskA = $this->createTask($archivedProject, null, SeoProjectTask::STATUS_PENDING);
        $taskB = $this->createTask($archivedProject, null, SeoProjectTask::STATUS_PENDING);
        $taskASnapshot = $taskA->only(['status', 'article_id', 'completed_at', 'scheduled_publish_at']);
        $taskBSnapshot = $taskB->only(['status', 'article_id', 'completed_at', 'scheduled_publish_at']);

        $activeProject = $this->createProject(userId: 93022, siteId: $siteId, name: 'Active reuse');
        $this->createTask($activeProject, (int) $reusedArticle->id, SeoProjectTask::STATUS_REVIEWING);

        $safeRevision = SeoArticleRevision::query()->create([
            'article_id' => (int) $safeArticle->id,
            'title' => 'safe-rev',
            'content' => '<p>safe</p>',
            'user_id' => 1,
        ]);
        $reusedRevision = SeoArticleRevision::query()->create([
            'article_id' => (int) $reusedArticle->id,
            'title' => 'reused-rev',
            'content' => '<p>reused</p>',
            'user_id' => 1,
        ]);

        $archive = SeoProjectArchive::query()->create([
            'project_id' => (int) $archivedProject->id,
            'site_id' => $siteId,
            'owner_id' => 93021,
            'project_name' => 'Archived cleanup',
            'articles_count' => 2,
            'total_articles' => 2,
            'archived_by' => 1,
            'archived_at' => now(),
        ]);

        SeoProjectArchiveItem::query()->create([
            'seo_project_archive_id' => (int) $archive->id,
            'article_id' => (int) $safeArticle->id,
            'task_id' => (int) $taskA->id,
            'position' => 1,
            'article_snapshot' => ['article_id' => (int) $safeArticle->id, 'title' => 'GC safe'],
        ]);
        SeoProjectArchiveItem::query()->create([
            'seo_project_archive_id' => (int) $archive->id,
            'article_id' => (int) $reusedArticle->id,
            'task_id' => (int) $taskB->id,
            'position' => 2,
            'article_snapshot' => ['article_id' => (int) $reusedArticle->id, 'title' => 'GC reused'],
        ]);

        $service = $this->resolveArchiveCleanupService();
        $stats = $service->cleanupArchivedWorkspace($archive->fresh(['project', 'items']), 1);

        self::assertSame(2, (int) ($stats['workspace_articles_considered'] ?? 0));
        self::assertSame(1, (int) ($stats['workspace_articles_safe'] ?? 0));
        self::assertSame(1, (int) ($stats['workspace_articles_skipped_reused'] ?? 0));
        self::assertGreaterThanOrEqual(1, (int) ($stats['editor_revisions_deleted'] ?? 0), 'stats='.json_encode($stats));

        self::assertNull(SeoArticleRevision::query()->find($safeRevision->id));
        self::assertNotNull(SeoArticleRevision::query()->find($reusedRevision->id));

        $taskA->refresh();
        $taskB->refresh();
        self::assertSame($taskASnapshot['status'], $taskA->status);
        self::assertSame($taskASnapshot['article_id'], $taskA->article_id);
        self::assertSame($taskBSnapshot['status'], $taskB->status);
        self::assertSame($taskBSnapshot['article_id'], $taskB->article_id);

        // Idempotent second run: no lifecycle mutation, reused revision still intact.
        $second = $service->cleanupArchivedWorkspace($archive->fresh(['project', 'items']), 1);
        self::assertSame(2, (int) ($second['workspace_articles_considered'] ?? 0));
        self::assertSame(1, (int) ($second['workspace_articles_safe'] ?? 0));
        self::assertSame(1, (int) ($second['workspace_articles_skipped_reused'] ?? 0));
        self::assertSame(0, (int) ($second['editor_revisions_deleted'] ?? 0));
        self::assertNotNull(SeoArticleRevision::query()->find($reusedRevision->id));

        $taskA->refresh();
        self::assertSame($taskASnapshot['status'], $taskA->status);
        self::assertSame($taskASnapshot['article_id'], $taskA->article_id);
    }

    public function test_shared_prompt_result_survives_when_still_linked_elsewhere(): void
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('prompt_results')
            || ! Schema::connection('omi_seo_ai')->hasTable('seo_prompt_result_links')
        ) {
            $this->markTestSkipped('Prompt result tables unavailable.');
        }

        $siteId = 93003;
        $safeArticle = $this->createArticle($siteId, 'Prompt safe');
        $otherArticle = $this->createArticle($siteId, 'Prompt other');

        $archivedProject = $this->createProject(userId: 93031, siteId: $siteId, name: 'Archived prompt');
        $archivedProject->forceFill(['archived_at' => now(), 'archived_by' => 1])->saveQuietly();
        $this->createTask($archivedProject, null, SeoProjectTask::STATUS_PENDING);

        $now = now();
        $promptId = (int) (\Illuminate\Support\Facades\DB::connection('omi_seo_ai')->table('prompts')->min('id') ?? 0);
        if ($promptId <= 0) {
            $promptId = (int) \Illuminate\Support\Facades\DB::connection('omi_seo_ai')->table('prompts')->insertGetId([
                'name' => 'gc-cleanup-'.$this->seq++,
                'user_id' => 1,
                'site_id' => $siteId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $promptResultId = (int) \Illuminate\Support\Facades\DB::connection('omi_seo_ai')->table('prompt_results')->insertGetId([
            'prompt_id' => $promptId,
            'status' => 'completed',
            'user_id' => 1,
            'site_id' => $siteId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        SeoPromptResultLink::query()->create([
            'prompt_result_id' => $promptResultId,
            'article_id' => (int) $safeArticle->id,
        ]);
        SeoPromptResultLink::query()->create([
            'prompt_result_id' => $promptResultId,
            'article_id' => (int) $otherArticle->id,
        ]);

        $archive = SeoProjectArchive::query()->create([
            'project_id' => (int) $archivedProject->id,
            'site_id' => $siteId,
            'owner_id' => 93031,
            'project_name' => 'Archived prompt',
            'articles_count' => 1,
            'total_articles' => 1,
            'archived_by' => 1,
            'archived_at' => now(),
        ]);
        SeoProjectArchiveItem::query()->create([
            'seo_project_archive_id' => (int) $archive->id,
            'article_id' => (int) $safeArticle->id,
            'position' => 1,
            'article_snapshot' => ['article_id' => (int) $safeArticle->id],
        ]);

        $stats = $this->resolveArchiveCleanupService()
            ->cleanupArchivedWorkspace($archive->fresh(['project', 'items']), 1);

        self::assertGreaterThanOrEqual(1, (int) ($stats['prompt_result_links_deleted'] ?? 0));
        self::assertSame(0, (int) ($stats['prompt_results_deleted'] ?? 0));
        self::assertTrue(
            \Illuminate\Support\Facades\DB::connection('omi_seo_ai')
                ->table('prompt_results')
                ->where('id', $promptResultId)
                ->exists(),
        );
        self::assertSame(
            1,
            SeoPromptResultLink::query()->where('prompt_result_id', $promptResultId)->count(),
        );
    }

    public function test_normal_archive_transition_destroys_workspace_resets_tasks_and_keeps_strong_cleanup(): void
    {
        $siteId = 93004;
        $article = $this->createArticle($siteId, 'Archive transition article');

        $project = $this->createProject(userId: 93041, siteId: $siteId, name: 'Active to archive');
        $task = $this->createTask($project, (int) $article->id, SeoProjectTask::STATUS_COMPLETED);
        $task->forceFill(['completed_at' => now()])->saveQuietly();

        // During archive(), this article still has an active task on the project being archived.
        // Manual-GC ownership would treat that as "active" — archive path must NOT skip it.
        self::assertTrue(
            app(ContentProjectArticleMembership::class)->belongsToActiveContentProject((int) $article->id),
        );

        $run = SeoProjectRun::query()->create([
            'project_id' => (int) $project->id,
            'user_id' => 93041,
            'mode' => SeoProjectRun::MODE_FULL,
            'status' => SeoProjectRun::STATUS_COMPLETED,
            'total' => 1,
            'succeeded' => 1,
            'failed' => 0,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
        $runItem = SeoProjectRunItem::query()->create([
            'run_id' => (int) $run->id,
            'task_id' => (int) $task->id,
            'article_id' => (int) $article->id,
            'action' => 'generate',
            'status' => 'completed',
            'attempt' => 1,
        ]);

        $revision = SeoArticleRevision::query()->create([
            'article_id' => (int) $article->id,
            'title' => 'pre-archive-rev',
            'content' => '<p>before archive</p>',
            'user_id' => 1,
        ]);

        $service = $this->resolveArchiveCleanupService();
        $archive = $service->archive($project->fresh(), 1, note: 'verify-strong-archive');

        $project->refresh();
        $task->refresh();

        self::assertNotNull($project->archived_at);
        self::assertSame(1, (int) $project->archived_by);

        self::assertInstanceOf(SeoProjectArchive::class, $archive);
        self::assertSame((int) $project->id, (int) $archive->project_id);
        self::assertNotNull($archive->archived_at);

        $item = SeoProjectArchiveItem::query()
            ->where('seo_project_archive_id', (int) $archive->id)
            ->where('article_id', (int) $article->id)
            ->first();
        self::assertNotNull($item);
        $snapshot = is_array($item->article_snapshot) ? $item->article_snapshot : [];
        self::assertSame((int) $article->id, (int) ($snapshot['article_id'] ?? 0));
        self::assertNotSame('', (string) ($snapshot['title'] ?? ''));

        // Strong cleanup: run/run-item gone; article-scoped revision cleaned despite active membership at destroy time.
        self::assertNull(SeoProjectRun::query()->find($run->id));
        self::assertNull(SeoProjectRunItem::query()->find($runItem->id));
        self::assertNull(SeoArticleRevision::query()->find($revision->id));

        // Fresh-flow reset on NORMAL archive only.
        self::assertSame(SeoProjectTask::STATUS_PENDING, (string) $task->status);
        self::assertNull($task->article_id);
        self::assertNull($task->completed_at);
    }

    public function test_manual_gc_queues_deferred_disk_and_cache_locks_only_for_safe_articles(): void
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_media')
            || ! Schema::connection('omi_seo_ai')->hasTable('seo_media_meta')
        ) {
            $this->markTestSkipped('seo_media tables unavailable.');
        }

        Storage::fake('public');

        $siteId = 93005;
        $safeArticle = $this->createArticle($siteId, 'Deferred safe');
        $reusedArticle = $this->createArticle($siteId, 'Deferred reused');

        $archivedProject = $this->createProject(userId: 93051, siteId: $siteId, name: 'Archived deferred');
        $archivedProject->forceFill(['archived_at' => now(), 'archived_by' => 1])->saveQuietly();
        $this->createTask($archivedProject, null, SeoProjectTask::STATUS_PENDING);

        $activeProject = $this->createProject(userId: 93052, siteId: $siteId, name: 'Active deferred reuse');
        $this->createTask($activeProject, (int) $reusedArticle->id, SeoProjectTask::STATUS_REVIEWING);

        $safePath = 'gc-deferred/safe-'.$this->seq.'.jpg';
        $reusedPath = 'gc-deferred/reused-'.$this->seq.'.jpg';
        Storage::disk('public')->put($safePath, 'safe-bytes');
        Storage::disk('public')->put($reusedPath, 'reused-bytes');

        $safeMedia = $this->createLocalMedia((int) $safeArticle->id, $siteId, $safePath);
        $reusedMedia = $this->createLocalMedia((int) $reusedArticle->id, $siteId, $reusedPath);

        $run = SeoProjectRun::query()->create([
            'project_id' => (int) $archivedProject->id,
            'user_id' => 93051,
            'mode' => SeoProjectRun::MODE_FULL,
            'status' => SeoProjectRun::STATUS_COMPLETED,
            'total' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'finished_at' => now(),
        ]);

        $archive = SeoProjectArchive::query()->create([
            'project_id' => (int) $archivedProject->id,
            'site_id' => $siteId,
            'owner_id' => 93051,
            'project_name' => 'Archived deferred',
            'articles_count' => 2,
            'total_articles' => 2,
            'archived_by' => 1,
            'archived_at' => now(),
        ]);
        SeoProjectArchiveItem::query()->create([
            'seo_project_archive_id' => (int) $archive->id,
            'article_id' => (int) $safeArticle->id,
            'position' => 1,
            'article_snapshot' => ['article_id' => (int) $safeArticle->id, 'title' => 'Deferred safe'],
        ]);
        SeoProjectArchiveItem::query()->create([
            'seo_project_archive_id' => (int) $archive->id,
            'article_id' => (int) $reusedArticle->id,
            'position' => 2,
            'article_snapshot' => ['article_id' => (int) $reusedArticle->id, 'title' => 'Deferred reused'],
        ]);

        $service = $this->resolveArchiveCleanupService();
        $historical = [(int) $safeArticle->id, (int) $reusedArticle->id];
        $partition = app(ContentProjectWorkspaceArticleOwnershipGuard::class)
            ->partitionHistoricalArticles($historical);

        self::assertSame([(int) $safeArticle->id], $partition['safe']);
        self::assertSame([(int) $reusedArticle->id], $partition['skipped_reused']);

        /** @var ContentProjectAiWorkspaceDestroyer $destroyer */
        $destroyer = app(ContentProjectAiWorkspaceDestroyer::class);
        $context = $destroyer->destroyManualGarbageCollection(
            $archivedProject->fresh(),
            $partition['safe'],
        );

        self::assertContains($safePath, $context->diskPathsToDelete());
        self::assertNotContains($reusedPath, $context->diskPathsToDelete());

        $locks = $context->cacheLockKeys();
        self::assertContains('seo-wp-publish-article-'.(int) $safeArticle->id, $locks);
        self::assertContains('manual-wp-sync:'.(int) $safeArticle->id, $locks);
        self::assertNotContains('seo-wp-publish-article-'.(int) $reusedArticle->id, $locks);
        self::assertNotContains('manual-wp-sync:'.(int) $reusedArticle->id, $locks);
        // Project/run-owned cleanup still happens for the archived project.
        self::assertContains('content-project-run-bulk-sync:'.(int) $run->id, $locks);
        self::assertNull(SeoProjectRun::query()->find($run->id));

        self::assertNull(SeoMedia::query()->find($safeMedia->id));
        self::assertNotNull(SeoMedia::query()->find($reusedMedia->id));

        $destroyer->releaseDeferredSideEffects($context);
        self::assertFalse(Storage::disk('public')->exists($safePath));
        self::assertTrue(Storage::disk('public')->exists($reusedPath));

        // Full service GC: same ownership outcome; second run stays safe/idempotent.
        $stats = $service->cleanupArchivedWorkspace($archive->fresh(['project', 'items']), 1);
        self::assertSame(2, (int) ($stats['workspace_articles_considered'] ?? 0));
        self::assertSame(1, (int) ($stats['workspace_articles_safe'] ?? 0));
        self::assertSame(1, (int) ($stats['workspace_articles_skipped_reused'] ?? 0));
        self::assertNotNull(SeoMedia::query()->find($reusedMedia->id));
        self::assertTrue(Storage::disk('public')->exists($reusedPath));

        $second = $service->cleanupArchivedWorkspace($archive->fresh(['project', 'items']), 1);
        self::assertSame(0, (int) ($second['local_media_deleted'] ?? 0));
        self::assertNotNull(SeoMedia::query()->find($reusedMedia->id));
        // Already-deleted safe path must not throw on repeat deferred release.
        $destroyer->releaseDeferredSideEffects($context);
        self::assertTrue(Storage::disk('public')->exists($reusedPath));
    }

    private function resolveArchiveCleanupService(): ArchiveContentProjectService
    {
        $registry = app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\ContentProjectWorkspaceCleanupRegistry::class);
        if ($registry->keys() === []) {
            foreach ([
                new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners\ExecutionWorkspaceCleaner,
                new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners\PromptWorkspaceCleaner,
                new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners\RuntimeWorkspaceCleaner,
                new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners\LocalMediaWorkspaceCleaner,
                new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners\GalleryExecutionWorkspaceCleaner,
                new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners\EditorRevisionWorkspaceCleaner,
                new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners\PendingArtifactsWorkspaceCleaner,
                new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners\CacheLockWorkspaceCleaner,
            ] as $cleaner) {
                $registry->register($cleaner);
            }
        }

        self::assertNotSame([], $registry->keys(), 'Workspace cleanup registry must have cleaners');

        $destroyer = new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\ContentProjectAiWorkspaceDestroyer($registry);
        app()->instance(
            \Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\ContentProjectAiWorkspaceDestroyer::class,
            $destroyer,
        );
        app()->forgetInstance(ArchiveContentProjectService::class);

        return app(ArchiveContentProjectService::class);
    }

    private function createLocalMedia(int $articleId, int $siteId, string $path): SeoMedia
    {
        $token = 'gc-media-'.$this->seq++;

        return SeoMedia::query()->create([
            'filename' => $token.'.jpg',
            'slug' => $token,
            'path' => $path,
            'url' => '/storage/'.$path,
            'source' => 'local',
            'status' => 'ready',
            'article_id' => $articleId,
            'site_id' => $siteId,
        ]);
    }

    private function createProject(int $userId, int $siteId, string $name): SeoProject
    {
        return SeoProject::query()->create([
            'name' => $name.' '.$this->seq++,
            'user_id' => $userId,
            'site_id' => $siteId,
            'month' => '2026-09-01',
            'status' => SeoProject::STATUS_MANUAL,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
        ]);
    }

    private function createTask(SeoProject $project, ?int $articleId, string $status): SeoProjectTask
    {
        return SeoProjectTask::query()->create([
            'project_id' => (int) $project->id,
            'site_id' => (int) $project->site_id,
            'type' => SeoProjectTask::TYPE_CREATE,
            'source_content' => 'gc-'.$this->seq++,
            'keyword' => 'gc-kw-'.$this->seq,
            'post_type' => SeoProjectTask::POST_TYPE_ARTICLE,
            'target_date' => '2026-09-10',
            'status' => $status,
            'article_id' => $articleId,
            'rewrite_mode' => SeoProjectTask::REWRITE_MODE_KEYWORD,
        ]);
    }

    private function createArticle(int $siteId, string $title): SeoArticle
    {
        $slug = 'gc-'.strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title) ?? 'a').'-'.$this->seq++;

        return SeoArticle::query()->create([
            'site_id' => $siteId,
            'title' => $title,
            'slug' => $slug,
            'body' => '<p>'.$title.'</p>',
        ]);
    }
}
