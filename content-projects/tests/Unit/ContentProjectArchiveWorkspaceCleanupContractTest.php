<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ContentProjectArchivePreview;
use Omnichannel\Addons\ContentProjects\Services\ArchiveContentProjectService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArticleMembership;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners\CacheLockWorkspaceCleaner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners\EditorRevisionWorkspaceCleaner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners\ExecutionWorkspaceCleaner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners\GalleryExecutionWorkspaceCleaner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners\LocalMediaWorkspaceCleaner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners\PendingArtifactsWorkspaceCleaner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners\PromptWorkspaceCleaner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners\RuntimeWorkspaceCleaner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\ContentProjectAiWorkspaceDestroyer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\ContentProjectWorkspaceArticleOwnershipGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\ContentProjectWorkspaceCleanupContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\ContentProjectWorkspaceCleanupRegistry;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\ContentProjectsServiceProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Contract: archive-time strong cleanup vs manual archived GC ownership safety.
 */
final class ContentProjectArchiveWorkspaceCleanupContractTest extends TestCase
{
    public function test_archive_transition_still_resets_tasks_after_destroy(): void
    {
        $source = $this->readMethodSource(
            (new ReflectionClass(ArchiveContentProjectService::class))->getMethod('archive'),
        );

        self::assertStringContainsString('workspaceDestroyer->destroyInTransaction', $source);
        self::assertStringContainsString('resetProjectTasksForFreshFlow', $source);
        self::assertStringContainsString('project_tasks_reset_for_fresh_flow', $source);
    }

    public function test_manual_cleanup_is_gc_only_without_task_reset(): void
    {
        $source = $this->readMethodSource(
            (new ReflectionClass(ArchiveContentProjectService::class))->getMethod('cleanupArchivedWorkspace'),
        );

        self::assertStringContainsString('articleOwnershipGuard->partitionHistoricalArticles', $source);
        self::assertStringContainsString('destroyManualGarbageCollection', $source);
        self::assertStringContainsString('workspace_articles_considered', $source);
        self::assertStringContainsString('workspace_articles_safe', $source);
        self::assertStringContainsString('workspace_articles_skipped_reused', $source);
        self::assertStringContainsString('skipped_reused_article_ids', $source);
        self::assertStringNotContainsString('resetProjectTasksForFreshFlow', $source);
        self::assertStringNotContainsString('destroyInTransaction', $source);
        self::assertStringNotContainsString('project_tasks_reset_for_fresh_flow', $source);
    }

    public function test_constructor_wires_ownership_guard(): void
    {
        $ctor = (new ReflectionClass(ArchiveContentProjectService::class))->getConstructor();
        self::assertNotNull($ctor);

        $types = array_map(
            static fn ($p) => $p->getType()?->getName(),
            $ctor->getParameters(),
        );

        self::assertContains(ContentProjectAiWorkspaceDestroyer::class, $types);
        self::assertContains(ContentProjectWorkspaceArticleOwnershipGuard::class, $types);
    }

    public function test_destroyer_exposes_manual_gc_path_without_merging_task_articles(): void
    {
        $destroyer = new ReflectionClass(ContentProjectAiWorkspaceDestroyer::class);
        self::assertTrue($destroyer->hasMethod('destroyInTransaction'));
        self::assertTrue($destroyer->hasMethod('destroyManualGarbageCollection'));
        self::assertTrue($destroyer->hasMethod('releaseDeferredSideEffects'));

        $manual = $this->readMethodSource($destroyer->getMethod('destroyManualGarbageCollection'));
        self::assertStringContainsString('mergeArticleIdsFromProjectTasks: false', $manual);

        $archivePath = $this->readMethodSource($destroyer->getMethod('destroyInTransaction'));
        self::assertStringContainsString('mergeArticleIdsFromProjectTasks: true', $archivePath);
    }

    public function test_ownership_guard_uses_active_content_project_membership(): void
    {
        $guardSource = (string) file_get_contents(
            (new ReflectionClass(ContentProjectWorkspaceArticleOwnershipGuard::class))->getFileName(),
        );
        self::assertStringContainsString('belongsToActiveContentProject', $guardSource);
        self::assertStringContainsString('skipped_reused', $guardSource);

        $membershipSource = (string) file_get_contents(
            (new ReflectionClass(ContentProjectArticleMembership::class))->getFileName(),
        );
        self::assertStringContainsString('function activeTaskForArticle', $membershipSource);
        self::assertStringContainsString("->whereNull('archived_at')", $membershipSource);
        self::assertStringContainsString('->active()', $membershipSource);

        $ctor = (new ReflectionClass(ContentProjectWorkspaceArticleOwnershipGuard::class))->getConstructor();
        self::assertNotNull($ctor);
        self::assertSame(
            ContentProjectArticleMembership::class,
            $ctor->getParameters()[0]->getType()?->getName(),
        );
    }

    public function test_prompt_cleaner_keeps_shared_prompt_results_with_surviving_links(): void
    {
        $source = $this->readMethodSource(
            (new ReflectionClass(PromptWorkspaceCleaner::class))->getMethod('clean'),
        );

        self::assertStringContainsString('stillLinked', $source);
        self::assertStringContainsString('orphanIds', $source);
        self::assertStringContainsString('array_diff($promptResultIds, $stillLinked)', $source);
    }

    public function test_cache_lock_cleaner_queues_only_context_article_ids(): void
    {
        $project = new SeoProject;
        $project->id = 99;

        $context = new ContentProjectWorkspaceCleanupContext(
            project: $project,
            articleIds: [11, 22],
            taskIds: [1],
            runIds: [7],
        );

        (new CacheLockWorkspaceCleaner)->clean($context);

        $keys = $context->cacheLockKeys();
        self::assertContains('seo-wp-publish-article-11', $keys);
        self::assertContains('seo-wp-publish-article-22', $keys);
        self::assertContains('manual-wp-sync:11', $keys);
        self::assertContains('content-project-run-bulk-sync:7', $keys);
        self::assertNotContains('seo-wp-publish-article-33', $keys);
    }

    public function test_preview_notification_surfaces_reused_skip_summary(): void
    {
        $source = $this->readMethodSource(
            (new ReflectionClass(ContentProjectArchivePreview::class))->getMethod('formatCleanupStats'),
        );

        self::assertStringContainsString('archive_cleanup_workspace_summary', $source);
        self::assertStringContainsString('workspace_articles_safe', $source);
        self::assertStringContainsString('workspace_articles_skipped_reused', $source);
    }

    public function test_manual_gc_logger_truncates_large_article_id_lists(): void
    {
        $source = $this->readMethodSource(
            (new ReflectionClass(ArchiveContentProjectService::class))->getMethod('cleanupArchivedWorkspace'),
        );

        self::assertStringContainsString('array_slice($safeArticleIds, 0, 50)', $source);
        self::assertStringContainsString('array_slice($skippedReusedArticleIds, 0, 50)', $source);
        self::assertStringContainsString('safe_article_ids_truncated', $source);
        self::assertStringContainsString('skipped_reused_article_ids_truncated', $source);
    }

    public function test_registered_cleaners_are_classified_and_article_scope_is_centralized(): void
    {
        $providerSource = (string) file_get_contents(
            (new ReflectionClass(ContentProjectsServiceProvider::class))->getFileName(),
        );

        $expectedKeys = [
            'execution',
            'prompt',
            'runtime',
            'local_media',
            'gallery_execution',
            'editor_revision',
            'pending_artifacts',
            'cache_lock',
        ];

        foreach ($expectedKeys as $key) {
            self::assertStringContainsString($key === 'execution'
                ? 'ExecutionWorkspaceCleaner'
                : match ($key) {
                    'prompt' => 'PromptWorkspaceCleaner',
                    'runtime' => 'RuntimeWorkspaceCleaner',
                    'local_media' => 'LocalMediaWorkspaceCleaner',
                    'gallery_execution' => 'GalleryExecutionWorkspaceCleaner',
                    'editor_revision' => 'EditorRevisionWorkspaceCleaner',
                    'pending_artifacts' => 'PendingArtifactsWorkspaceCleaner',
                    'cache_lock' => 'CacheLockWorkspaceCleaner',
                    default => $key,
                }, $providerSource);
        }

        // A. project/run/task scoped (may also have article orphan branch)
        $execution = $this->readMethodSource((new ReflectionClass(ExecutionWorkspaceCleaner::class))->getMethod('clean'));
        self::assertStringContainsString('runIds()', $execution);
        self::assertStringContainsString('taskIds()', $execution);
        self::assertStringContainsString('articleIds()', $execution); // mixed: orphan run-items by article

        // C. mixed prompt: run/task/article filters, but articleIds come from context only
        $prompt = $this->readMethodSource((new ReflectionClass(PromptWorkspaceCleaner::class))->getMethod('clean'));
        self::assertStringContainsString('articleIds()', $prompt);
        self::assertStringContainsString('runIds()', $prompt);
        self::assertStringContainsString('taskIds()', $prompt);

        // B. article scoped only
        foreach ([
            RuntimeWorkspaceCleaner::class,
            LocalMediaWorkspaceCleaner::class,
            GalleryExecutionWorkspaceCleaner::class,
            EditorRevisionWorkspaceCleaner::class,
            PendingArtifactsWorkspaceCleaner::class,
        ] as $class) {
            $source = $this->readMethodSource((new ReflectionClass($class))->getMethod('clean'));
            self::assertStringContainsString('articleIds()', $source);
            self::assertStringNotContainsString('runIds()', $source);
            self::assertStringNotContainsString('taskIds()', $source);
        }

        // mixed cache lock: article + run keys from context
        $cache = $this->readMethodSource((new ReflectionClass(CacheLockWorkspaceCleaner::class))->getMethod('clean'));
        self::assertStringContainsString('articleIds()', $cache);
        self::assertStringContainsString('runIds()', $cache);

        // Centralized ownership: cleaners never call membership/guard themselves.
        foreach ([
            ExecutionWorkspaceCleaner::class,
            PromptWorkspaceCleaner::class,
            RuntimeWorkspaceCleaner::class,
            LocalMediaWorkspaceCleaner::class,
            GalleryExecutionWorkspaceCleaner::class,
            EditorRevisionWorkspaceCleaner::class,
            PendingArtifactsWorkspaceCleaner::class,
            CacheLockWorkspaceCleaner::class,
        ] as $class) {
            $file = (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());
            self::assertStringNotContainsString('ContentProjectArticleMembership', $file);
            self::assertStringNotContainsString('ContentProjectWorkspaceArticleOwnershipGuard', $file);
            self::assertStringNotContainsString('belongsToActiveContentProject', $file);
        }

        $manualDestroy = $this->readMethodSource(
            (new ReflectionClass(ContentProjectAiWorkspaceDestroyer::class))->getMethod('destroyManualGarbageCollection'),
        );
        self::assertStringContainsString('mergeArticleIdsFromProjectTasks: false', $manualDestroy);

        $registryRef = new ReflectionClass(ContentProjectWorkspaceCleanupRegistry::class);
        self::assertTrue($registryRef->hasMethod('all'));
        self::assertTrue($registryRef->hasMethod('keys'));
    }

    private function readMethodSource(ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }
}
