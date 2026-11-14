<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ListSeoProjects;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectListBucket;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ContentProjectCmDefaultAllMonthsContractTest extends TestCase
{
    public function test_list_page_supports_explicit_all_month_selection(): void
    {
        self::assertTrue(method_exists(ListSeoProjects::class, 'normalizePlanningMonthSelection'));
        self::assertTrue(method_exists(ListSeoProjects::class, 'isAllPlanningMonths'));

        // Invoke without Livewire mount — method is pure string normalization.
        $method = new ReflectionMethod(ListSeoProjects::class, 'normalizePlanningMonthSelection');
        /** @var object $proxy */
        $proxy = (new ReflectionClass(ListSeoProjects::class))->newInstanceWithoutConstructor();
        self::assertSame('', $method->invoke($proxy, null));
        self::assertSame('', $method->invoke($proxy, ''));
        self::assertSame('', $method->invoke($proxy, 'all'));
        self::assertSame('', $method->invoke($proxy, 'ALL'));
        self::assertSame('2026-11', $method->invoke($proxy, '2026-11'));
    }

    public function test_cm_default_all_and_query_null_month_are_wired(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ListSeoProjects::class))->getFileName(),
        );

        self::assertStringContainsString('usesContentManagerOpsPresentation()', $src);
        self::assertStringContainsString("return '';", $src);
        self::assertStringContainsString("params['month'] = 'all'", $src);
        self::assertStringContainsString('planningMonthDateOrNull', $src);
        self::assertStringContainsString('normalizePlanningMonthSelection', $src);

        $bucket = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectListBucket::class))->getFileName(),
        );
        self::assertStringContainsString('if ($monthDate === null)', $bucket);

        $resolve = new ReflectionMethod(ListSeoProjects::class, 'resolvePlanningMonthFromRequest');
        self::assertTrue($resolve->isPrivate());
    }

    public function test_month_nav_exposes_all_option(): void
    {
        $nav = (string) file_get_contents(
            dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/components/content-project-list-month-nav.blade.php',
        );
        $page = (string) file_get_contents(
            dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/filament/resources/seo-project-resource/pages/list-seo-projects.blade.php',
        );

        self::assertStringContainsString('is-all-months', $page);
        self::assertStringContainsString('all-months-url', $page);
        self::assertStringContainsString('month-catalog', $page);
        self::assertStringContainsString('active_month_all', $nav);
        self::assertStringContainsString('$isAllMonths', $nav);
        self::assertStringContainsString('shiftStrip', $nav);
    }
}
