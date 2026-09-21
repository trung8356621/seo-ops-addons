<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ListSeoProjects;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectMonthlyWorkloadService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\SitePlanningActiveUnitAggregator;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\SitePlanningReadModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Contract: Projects-list Monthly Planning excludes Global Legacy; Planner overview does not.
 */
final class ProjectsListMonthlyPlanningGlobalLegacyExclusionContractTest extends TestCase
{
    public function test_projects_list_uses_scoped_overview_not_default(): void
    {
        $list = (string) file_get_contents(
            (string) (new ReflectionClass(ListSeoProjects::class))->getFileName(),
        );
        $read = (string) file_get_contents(
            (string) (new ReflectionClass(SitePlanningReadModel::class))->getFileName(),
        );
        $agg = (string) file_get_contents(
            (string) (new ReflectionClass(SitePlanningActiveUnitAggregator::class))->getFileName(),
        );
        $planner = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );
        $workload = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectMonthlyWorkloadService::class))->getFileName(),
        );

        self::assertStringContainsString('overviewForProjectsList', $list);
        self::assertStringContainsString('getMonthlyPlanningMatrix', $list);
        self::assertStringNotContainsString('->overview(null, $this->planningMonth', $list);

        self::assertStringContainsString('function overviewForProjectsList', $read);
        self::assertStringContainsString('excludeGlobalLegacy: true', $read);
        self::assertStringContainsString('excludeGlobalLegacy: false', $read);

        self::assertStringContainsString('ContentProjectGlobalLegacyArchive::excludeFromProjectAlias', $agg);
        self::assertStringContainsString('bool $excludeGlobalLegacy = false', $agg);

        self::assertStringContainsString('->overview(null, $this->resolvePlannerActiveMonth())', $planner);
        self::assertStringNotContainsString('overviewForProjectsList', $planner);

        self::assertStringContainsString('ContentProjectGlobalLegacyArchive::excludeFromProjectAlias', $workload);
    }
}
