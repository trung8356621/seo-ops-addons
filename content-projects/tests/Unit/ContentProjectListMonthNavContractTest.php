<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ListSeoProjects;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Content Projects list: month strip — arrows paginate display only; chips select month.
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

    public function test_list_nav_arrows_paginate_strip_without_navigation(): void
    {
        $view = LegacyAddonPath::read(
            'resources/views/filament/resources/seo-project-resource/pages/list-seo-projects.blade.php',
        );
        $nav = LegacyAddonPath::read(
            'resources/views/components/content-project-list-month-nav.blade.php',
        );

        self::assertStringContainsString('data-cp-list-month-nav', $nav);
        self::assertStringContainsString('data-cp-month-strip', $nav);
        self::assertStringContainsString('cpListMonthStrip', $nav);
        self::assertStringContainsString('shiftStrip', $nav);
        self::assertStringContainsString('type="button"', $nav);
        self::assertStringContainsString('@click.prevent="shiftStrip(-1)"', $nav);
        self::assertStringContainsString('@click.prevent="shiftStrip(1)"', $nav);
        self::assertStringContainsString('active_month_strip_prev', $nav);
        self::assertStringContainsString('active_month_strip_next', $nav);
        self::assertStringContainsString('active_month_all', $nav);
        self::assertStringContainsString('month-catalog', $view);
        self::assertStringContainsString('selectOptions(18, 12)', $view);
        // Arrows must not wire:navigate / href to month URLs.
        self::assertStringNotContainsString(':prev-url', $view);
        self::assertStringNotContainsString(':next-url', $view);
        self::assertStringNotContainsString('href="{{ $prevUrl }}"', $nav);
        self::assertStringNotContainsString('href="{{ $nextUrl }}"', $nav);
        self::assertStringNotContainsString('wire:model.live="planningMonth"', $nav);
        self::assertStringNotContainsString('planning-month-jump', $nav);
    }

    public function test_month_chip_click_still_uses_url_navigation(): void
    {
        $nav = LegacyAddonPath::read(
            'resources/views/components/content-project-list-month-nav.blade.php',
        );
        self::assertStringContainsString('wire:navigate', $nav);
        self::assertStringContainsString(':href="month.url"', $nav);
        self::assertStringContainsString('visibleMonths', $nav);
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
