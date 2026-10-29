<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewSeoProject;
use Omnichannel\Addons\ContentProjects\Services\ArchiveContentProjectService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ArchiveContentProjectHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyBatchSnapshotBuilder;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Workspace\Cleaners\LocalMediaWorkspaceCleaner;
use Omnichannel\Addons\Content\Services\ArticleEditorView;
use Omnichannel\Addons\Content\Support\ArticleFeaturedImageResolver;
use Omnichannel\Addons\Media\Services\ArticleMediaStateOrphanRepair;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Post-#900 archive hardening: toast count, local media ownership, archived isolation.
 */
final class ContentProjectArchiveLifecycleHardeningTest extends TestCase
{
    public function test_archive_handler_uses_snapshot_article_count_not_literal_one(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(ArchiveContentProjectHandler::class))->getFileName(),
        );

        self::assertStringNotContainsString("'affected_count' => 1", $source);
        self::assertStringContainsString('articles_count', $source);
        self::assertStringContainsString('affected_count', $source);
        self::assertStringContainsString('max(0, $affectedCount)', $source);
    }

    public function test_archive_ui_toast_uses_affected_count_metadata(): void
    {
        $resource = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectResource::class))->getFileName(),
        );
        $view = (string) file_get_contents(
            (string) (new ReflectionClass(ViewSeoProject::class))->getFileName(),
        );

        self::assertStringContainsString("metadata['affected_count']", $resource);
        self::assertStringContainsString('archive_completed_project_body', $resource);
        self::assertStringContainsString("metadata['affected_count']", $view);
        self::assertStringContainsString('archive_completed_project_body', $view);
    }

    public function test_local_media_cleaner_distinguishes_wp_shared_and_unsynced(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(LocalMediaWorkspaceCleaner::class))->getFileName(),
        );

        self::assertStringContainsString('ArticleMediaState', $source);
        self::assertStringContainsString('wp_attachment_id', $source);
        self::assertStringContainsString('mustKeepForUnsyncedFeatured', $source);
        self::assertStringContainsString('nullOrphanStateMediaIds', $source);
        self::assertStringContainsString('local_media_shared_detached', $source);
        self::assertStringContainsString('WordpressArticleLink', $source);
        self::assertStringContainsString('seo_media_meta', $source);
    }

    public function test_editor_view_fail_softs_missing_featured_media_id(): void
    {
        $source = $this->readMethodSource(
            (new ReflectionClass(ArticleEditorView::class))->getMethod('mediaSection'),
        );

        self::assertStringContainsString('SeoMedia::query()->whereKey', $source);
        self::assertStringContainsString('display_url', $source);
    }

    public function test_list_featured_resolver_keeps_display_url_as_presentation_sot(): void
    {
        $source = $this->readMethodSource(
            (new ReflectionClass(ArticleFeaturedImageResolver::class))->getMethod('forList'),
        );

        self::assertStringContainsString('display_url', $source);
        self::assertStringContainsString('media_id', $source);
    }

    public function test_global_monthly_snapshot_excludes_archived_projects(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(GlobalMonthlyBatchSnapshotBuilder::class))->getFileName(),
        );

        self::assertStringContainsString("whereNull('archived_at')", $source);
        self::assertStringContainsString('isArchive()', $source);
        self::assertStringContainsString('isDraftPlanning()', $source);
    }

    public function test_publishing_queue_rejects_archived_project(): void
    {
        $source = $this->readMethodSource(
            (new ReflectionClass(ContentProjectPublishingQueueService::class))->getMethod('assertProjectActive'),
        ); // queue service rejects archived projects

        self::assertStringContainsString('archived_at', $source);
        self::assertStringContainsString('isArchive()', $source);
    }

    public function test_archive_service_resets_tasks_but_keeps_project_archived_flag(): void
    {
        $source = $this->readMethodSource(
            (new ReflectionClass(ArchiveContentProjectService::class))->getMethod('resetProjectTasksForFreshFlow'),
        );

        self::assertStringContainsString("STATUS_PENDING", $source);
        self::assertStringContainsString("'article_id' => null", $source);
        self::assertStringContainsString('publish_queue_status', $source);
    }

    public function test_orphan_media_state_repair_keeps_display_url_contract(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleMediaStateOrphanRepair::class))->getFileName(),
        );

        self::assertStringContainsString('nullMissingMediaIds', $source);
        self::assertStringContainsString("update(['media_id' => null])", $source);
        self::assertStringContainsString('display_url', $source);
        self::assertStringContainsString('Do NOT run against production', $source);
    }

    private function readMethodSource(ReflectionMethod $method): string
    {
        $file = (string) $method->getFileName();
        $start = (int) $method->getStartLine();
        $end = (int) $method->getEndLine();
        $lines = file($file);
        self::assertIsArray($lines);

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}
