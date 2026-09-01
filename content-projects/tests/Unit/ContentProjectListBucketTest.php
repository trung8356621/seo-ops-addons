<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectListBucket;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

final class ContentProjectListBucketTest extends TestCase
{
    public function test_dropdown_values_exclude_draft_but_keep_archived(): void
    {
        self::assertSame(
            [
                ContentProjectListBucket::ALL,
                ContentProjectListBucket::PROJECT,
                ContentProjectListBucket::ARCHIVED,
            ],
            ContentProjectListBucket::values(),
        );
        self::assertNotContains(ContentProjectListBucket::DRAFT, ContentProjectListBucket::values());
        self::assertNotContains(SeoProject::STATUS_PENDING, ContentProjectListBucket::values());
        self::assertNotContains(SeoProject::STATUS_MANUAL, ContentProjectListBucket::values());
        self::assertNotContains(SeoProject::STATUS_RUNNING, ContentProjectListBucket::values());
        self::assertNotContains(SeoProject::STATUS_COMPLETED, ContentProjectListBucket::values());
        self::assertNotContains(SeoProject::STATUS_PAUSED, ContentProjectListBucket::values());
        self::assertNotContains(SeoProject::STATUS_APPROVED, ContentProjectListBucket::values());
    }

    public function test_legacy_raw_status_maps_to_project_bucket(): void
    {
        self::assertSame(ContentProjectListBucket::PROJECT, ContentProjectListBucket::normalize('pending'));
        self::assertSame(ContentProjectListBucket::PROJECT, ContentProjectListBucket::normalize('manual'));
        self::assertSame(ContentProjectListBucket::PROJECT, ContentProjectListBucket::normalize('running'));
        self::assertSame(ContentProjectListBucket::PROJECT, ContentProjectListBucket::normalize('completed'));
        self::assertSame(ContentProjectListBucket::PROJECT, ContentProjectListBucket::normalize('paused'));
        self::assertSame(ContentProjectListBucket::PROJECT, ContentProjectListBucket::normalize('approved'));
        self::assertSame(ContentProjectListBucket::ALL, ContentProjectListBucket::normalize('draft'));
        self::assertSame(ContentProjectListBucket::ALL, ContentProjectListBucket::normalize(SeoProject::STATUS_DRAFT));
        self::assertSame(ContentProjectListBucket::ARCHIVED, ContentProjectListBucket::normalize('archived'));
        self::assertSame(ContentProjectListBucket::ALL, ContentProjectListBucket::normalize('all'));
    }

    public function test_select_options_do_not_expose_draft(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectListBucket::class))->getFileName(),
        );

        $start = strpos($src, 'function selectOptions');
        self::assertNotFalse($start);
        $chunk = substr($src, $start, 450);

        self::assertStringNotContainsString('self::DRAFT', $chunk);
        self::assertStringContainsString('self::ALL', $chunk);
        self::assertStringContainsString('self::PROJECT', $chunk);
        self::assertStringContainsString('self::ARCHIVED', $chunk);
    }

    public function test_apply_source_excludes_draft_from_all_and_keeps_archived_bucket(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectListBucket::class))->getFileName(),
        );

        self::assertStringContainsString("STATUS_DRAFT", $src);
        self::assertStringContainsString("whereNull('archived_at')", $src);
        self::assertStringContainsString("whereNotNull('archived_at')", $src);
        self::assertStringContainsString("where('status', '!=', SeoProject::STATUS_DRAFT)", $src);
        self::assertStringContainsString('applyExecutionMonth', $src);
        self::assertStringContainsString('applyAllBucket', $src);
        self::assertStringContainsString('Shared Planning Draft never appears on this list', $src);
        self::assertStringNotContainsString('self::DRAFT =>', $src);
    }

    public function test_all_bucket_excludes_draft_and_does_not_force_active_only(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectListBucket::class))->getFileName(),
        );

        $start = strpos($src, 'function applyAllBucket');
        self::assertNotFalse($start);
        $chunk = substr($src, $start, 500);
        self::assertStringContainsString("where('status', '!=', SeoProject::STATUS_DRAFT)", $chunk);
        self::assertStringContainsString('applyExecutionMonth', $chunk);
        self::assertStringNotContainsString("where('status', SeoProject::STATUS_DRAFT)", $chunk);
        self::assertStringNotContainsString("whereNull('archived_at')", $chunk);
    }

    public function test_list_page_and_resource_no_longer_expose_raw_status_filter(): void
    {
        $list = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Filament/Resources/SeoProjectResource/Pages/ListSeoProjects.php',
        );
        self::assertStringContainsString('projectType', $list);
        self::assertStringContainsString('ContentProjectListBucket', $list);
        self::assertStringContainsString('project_type', $list);
        self::assertStringNotContainsString('SeoProject::statusOptions()', $list);
        self::assertStringNotContainsString('getPlanningStatusOptions', $list);
        self::assertStringNotContainsString('planningStatus', $list);

        $resource = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Filament/Resources/SeoProjectResource.php',
        );
        self::assertStringContainsString("Filter::make('project_type')", $resource);
        self::assertStringNotContainsString("SelectFilter::make('status')", $resource);
        self::assertStringNotContainsString('->options(SeoProject::statusOptions())', $resource);
        self::assertStringNotContainsString('ContentProjectListBucket::DRAFT', $resource);
        self::assertStringContainsString('ContentProjectListBucket::ARCHIVED', $resource);
        self::assertStringContainsString('hasListOverflowActions', $resource);

        $blade = LegacyAddonPath::read('resources/views/filament/resources/seo-project-resource/pages/list-seo-projects.blade.php');
        self::assertStringContainsString('wire:model.live="projectType"', $blade);
        self::assertStringNotContainsString('planningStatus', $blade);
        self::assertStringNotContainsString('getPlanningStatusOptions', $blade);
    }

    public function test_status_column_still_uses_lifecycle_presenter(): void
    {
        $resource = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Filament/Resources/SeoProjectResource.php',
        );
        self::assertStringContainsString("TextColumn::make('status')", $resource);
        self::assertStringContainsString('ContentProjectProjectStatusPresenter::label', $resource);
    }

    public function test_lang_has_project_type_labels(): void
    {
        $en = LegacyAddonPath::read('lang/en/filament.php');
        $vi = LegacyAddonPath::read('lang/vi/filament.php');
        foreach (['project_type_all', 'project_type_draft', 'project_type_project', 'project_type_archived'] as $key) {
            self::assertStringContainsString("'".$key."'", $en);
            self::assertStringContainsString("'".$key."'", $vi);
        }
    }
}
