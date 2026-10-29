<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewSeoProject;
use Omnichannel\Addons\ContentProjects\Services\ArchiveContentProjectService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Domain-neutral projects (site_id null/0) must not call canAccessSite(0) on Archive.
 * Regression: Project #900 production archive failed with HttpException 403.
 */
final class ContentProjectArchiveDomainNeutralAccessTest extends TestCase
{
    public function test_resource_exposes_archive_access_helper_that_avoids_site_zero(): void
    {
        $ref = new ReflectionClass(SeoProjectResource::class);
        self::assertTrue($ref->hasMethod('canAccessArchiveProjectAction'));
        self::assertTrue($ref->hasMethod('archiveFailureMessage'));
        self::assertTrue($ref->hasMethod('canAccessPublishingQueueProject'));

        $helper = $ref->getMethod('canAccessArchiveProjectAction');
        $source = $this->readMethodSource($helper);
        self::assertStringContainsString('canArchiveContentProjects', $source);
        self::assertStringContainsString('canAccessPublishingQueueProject', $source);
        self::assertStringNotContainsString('canAccessSite', $source);
    }

    public function test_list_archive_action_does_not_call_can_access_site_zero(): void
    {
        $source = (string) file_get_contents((string) (new ReflectionClass(SeoProjectResource::class))->getFileName());
        $archivePos = strpos($source, "Action::make('archive_project')");
        self::assertNotFalse($archivePos);

        $slice = substr($source, $archivePos, 7000);
        self::assertStringContainsString('canAccessArchiveProjectAction', $slice);
        self::assertStringContainsString('archiveFailureMessage', $slice);
        self::assertStringContainsString('archive_completed', $slice);
        self::assertStringNotContainsString('canAccessSite((int) ($record->site_id ?? 0))', $slice);
        self::assertStringContainsString('$siteId > 0 ? $siteId : null', $slice);
    }

    public function test_view_archive_action_does_not_call_can_access_site_zero(): void
    {
        $source = (string) file_get_contents((string) (new ReflectionClass(ViewSeoProject::class))->getFileName());
        $archivePos = strpos($source, "Action::make('archive_project')");
        self::assertNotFalse($archivePos);

        $slice = substr($source, $archivePos, 7000);
        self::assertStringContainsString('canAccessArchiveProjectAction', $slice);
        self::assertStringContainsString('archiveFailureMessage', $slice);
        self::assertStringNotContainsString('canAccessSite((int) ($project->site_id ?? 0))', $slice);
    }

    public function test_archive_service_isolates_post_commit_side_effects(): void
    {
        $ref = new ReflectionClass(ArchiveContentProjectService::class);
        $archive = $ref->getMethod('archive');
        $source = $this->readMethodSource($archive);

        self::assertStringContainsString('refreshSiteStatisticsAfterArchive', $source);
        self::assertStringContainsString('content_project.archive_deferred_cleanup', $source);
        self::assertStringNotContainsString(
            '->afterSuccessfulArchive((int) ($archive->site_id ?? 0));',
            $source,
        );
        self::assertStringNotContainsString("'affected_count' => 1", (string) file_get_contents(
            (string) (new \ReflectionClass(
                \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\ArchiveContentProjectHandler::class
            ))->getFileName()
        ));

        self::assertTrue($ref->hasMethod('resolveArchiveDomainId'));
        self::assertTrue($ref->hasMethod('refreshSiteStatisticsAfterArchive'));

        $refreshSource = $this->readMethodSource($ref->getMethod('refreshSiteStatisticsAfterArchive'));
        self::assertStringContainsString('resolveProjectItemSiteIds', $refreshSource);
        self::assertStringContainsString('content_project.archive_site_statistics', $refreshSource);
    }

    public function test_build_summary_uses_item_site_for_domain_neutral_projects(): void
    {
        $ref = new ReflectionClass(ArchiveContentProjectService::class);
        $buildSummary = $this->readMethodSource($ref->getMethod('buildSummary'));
        self::assertStringContainsString('resolveArchiveDomainId', $buildSummary);

        $resolve = $this->readMethodSource($ref->getMethod('resolveArchiveDomainId'));
        self::assertStringContainsString('resolveProjectItemSiteIds', $resolve);
        self::assertStringContainsString('count($itemSiteIds) === 1', $resolve);
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
