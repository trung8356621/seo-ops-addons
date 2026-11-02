<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ListSeoProjects;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectEditorialListIndex;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ContentProjectListViewModeContractTest extends TestCase
{
    public function test_list_page_defines_generate_and_editorial_modes(): void
    {
        self::assertSame('generate', ListSeoProjects::VIEW_GENERATE);
        self::assertSame('editorial', ListSeoProjects::VIEW_EDITORIAL);
        self::assertSame('generate', ListSeoProjects::normalizeListViewMode(null));
        self::assertSame('generate', ListSeoProjects::normalizeListViewMode('generate'));
        self::assertSame('editorial', ListSeoProjects::normalizeListViewMode('editorial'));
        self::assertSame('generate', ListSeoProjects::normalizeListViewMode('bogus'));
    }

    public function test_content_manager_presentation_forces_editorial_and_hides_switch(): void
    {
        $list = (string) file_get_contents(
            (string) (new ReflectionClass(ListSeoProjects::class))->getFileName(),
        );
        $view = (string) file_get_contents(
            dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/filament/resources/seo-project-resource/pages/list-seo-projects.blade.php',
        );

        self::assertStringContainsString('usesContentManagerOpsPresentation()', $list);
        self::assertStringContainsString('VIEW_EDITORIAL', $list);
        self::assertStringContainsString('canSwitchListViewMode', $list);
        self::assertStringContainsString("#[Url(as: 'view'", $list);
        self::assertStringContainsString('canSwitchListViewMode()', $view);
        self::assertStringContainsString("wire:click=\"\$set('listViewMode', 'generate')\"", $view);
        self::assertStringContainsString("wire:click=\"\$set('listViewMode', 'editorial')\"", $view);
        self::assertStringContainsString('list_view_generate', $view);
        self::assertStringContainsString('list_view_editorial', $view);
    }

    public function test_resource_columns_split_by_effective_view_mode(): void
    {
        $resource = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectResource::class))->getFileName(),
        );

        self::assertStringContainsString('listTableViewMode()', $resource);
        self::assertStringContainsString("TextColumn::make('active_generated_count')", $resource);
        self::assertStringContainsString("TextColumn::make('ops_normal_count')", $resource);
        self::assertStringContainsString("TextColumn::make('ops_needs_review_count')", $resource);
        self::assertStringContainsString("TextColumn::make('ops_reviewed_count')", $resource);
        self::assertStringContainsString('VIEW_GENERATE', $resource);
        self::assertStringContainsString('VIEW_EDITORIAL', $resource);
        self::assertStringNotContainsString('needs_editing_count', $resource);
    }

    public function test_editorial_mode_loads_batch_kpi_index_not_per_row_read_model(): void
    {
        $list = (string) file_get_contents(
            (string) (new ReflectionClass(ListSeoProjects::class))->getFileName(),
        );

        self::assertStringContainsString('ContentProjectEditorialListIndex::countsByProject', $list);
        self::assertStringContainsString('attachEditorialKpiCounts', $list);
        self::assertStringNotContainsString('ContentProjectItemOperationsReadModel', $list);
        self::assertTrue(class_exists(ContentProjectEditorialListIndex::class));
    }

    public function test_effective_mode_methods_exist_on_list_page(): void
    {
        self::assertTrue(method_exists(ListSeoProjects::class, 'effectiveListViewMode'));
        self::assertTrue(method_exists(ListSeoProjects::class, 'canSwitchListViewMode'));
        self::assertTrue(method_exists(SeoProjectResource::class, 'listTableViewMode'));

        $effective = new ReflectionMethod(ListSeoProjects::class, 'effectiveListViewMode');
        self::assertTrue($effective->isPublic());
        self::assertTrue((new ReflectionMethod(SeoAccessControl::class, 'usesContentManagerOpsPresentation'))->isPublic());
    }
}
