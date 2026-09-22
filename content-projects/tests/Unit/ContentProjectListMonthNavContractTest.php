<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ListSeoProjects;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Content Projects list: single numeric month navigator (no selectbox).
 */
final class ContentProjectListMonthNavContractTest extends TestCase
{
    public function test_short_label_is_numeric_month(): void
    {
        self::assertSame('07', ContentProjectMonthContext::shortLabel('2026-07'));
        self::assertSame('09', ContentProjectMonthContext::shortLabel('2026-09'));
        self::assertSame('12', ContentProjectMonthContext::shortLabel('2026-12'));
        self::assertSame('01', ContentProjectMonthContext::shortLabel('2026-01'));
    }

    public function test_year_boundary_nearby_months_and_labels(): void
    {
        self::assertSame(
            ['2025-11', '2025-12', '2026-01', '2026-02', '2026-03'],
            ContentProjectMonthContext::nearbyMonths('2026-01', 2),
        );
        self::assertSame(
            ['11', '12', '01', '02', '03'],
            array_map(
                static fn (string $month): string => ContentProjectMonthContext::shortLabel($month),
                ContentProjectMonthContext::nearbyMonths('2026-01', 2),
            ),
        );
        self::assertSame('2025-12', ContentProjectMonthContext::shift('2026-01', -1));
        self::assertSame('2026-02', ContentProjectMonthContext::shift('2026-01', 1));
    }

    public function test_list_nav_has_no_month_selectbox(): void
    {
        $view = LegacyAddonPath::read(
            'resources/views/filament/resources/seo-project-resource/pages/list-seo-projects.blade.php',
        );
        $nav = LegacyAddonPath::read(
            'resources/views/components/content-project-list-month-nav.blade.php',
        );

        self::assertStringContainsString('data-cp-list-month-nav', $nav);
        self::assertStringContainsString('shortLabel', $nav);
        self::assertStringContainsString('monthUrls', $nav);
        self::assertStringContainsString('prevUrl', $nav);
        self::assertStringContainsString('nextUrl', $nav);
        self::assertStringNotContainsString('planning-month-jump', $nav);
        self::assertStringNotContainsString('wire:model.live="planningMonth"', $nav);
        self::assertStringNotContainsString('<x-select', $nav);
        self::assertStringNotContainsString(':all-options', $view);
        self::assertStringNotContainsString('getPlanningMonthOptions()', $view);
    }

    public function test_list_page_still_syncs_month_via_url(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ListSeoProjects::class))->getFileName(),
        );

        self::assertStringContainsString('planningMonthUrl', $src);
        self::assertStringContainsString('tableFilters', $src);
        self::assertStringContainsString('resolvePlanningMonthFromRequest', $src);
        self::assertStringContainsString('getDomainWorkloadChart', $src);
        self::assertStringContainsString('getWriterWorkloadChart', $src);
        self::assertStringContainsString('getMonthlyPlanningMatrix', $src);
    }
}
