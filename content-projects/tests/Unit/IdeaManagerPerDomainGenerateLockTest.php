<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectIdeaManager;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager\IdeaManagerDistributionService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionPlannerService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner\ContentProjectPlannerRunService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

final class IdeaManagerPerDomainGenerateLockTest extends TestCase
{
    public function test_backend_active_run_lock_and_reconcile_are_scoped_by_site_id(): void
    {
        $runSvc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectPlannerRunService::class))->getFileName(),
        );
        self::assertStringContainsString('function findActive(SeoProject $project, string $sourceType, ?int $siteId = null)', $runSvc);
        self::assertStringContainsString("->where('site_id', \$siteId)", $runSvc);
        self::assertStringContainsString('function activeRunsBySite(', $runSvc);

        $plannerSvc = (string) file_get_contents(
            (string) (new ReflectionClass(NewContentSuggestionPlannerService::class))->getFileName(),
        );
        self::assertMatchesRegularExpression(
            '/->findActive\(\s*\$project,\s*SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT,\s*\(int\) \$site->getKey\(\),/',
            $plannerSvc,
        );

        $distSvc = (string) file_get_contents(
            (string) (new ReflectionClass(IdeaManagerDistributionService::class))->getFileName(),
        );
        self::assertStringContainsString("->where('site_id', \$targetSiteId)", $distSvc);
        self::assertStringContainsString("->where('source_type', SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT)", $distSvc);
        self::assertStringContainsString('function activeRunsBySite(', $distSvc);
        self::assertStringNotContainsString("->where('source', SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT)", $distSvc);

        $pageSrc = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectIdeaManager::class))->getFileName(),
        );
        self::assertStringContainsString('function activeDistributionRunsBySite(', $pageSrc);
    }

    public function test_idea_manager_blade_scopes_generating_polling_and_results_per_site(): void
    {
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-idea-manager.blade.php');

        self::assertStringContainsString('activeRunsBySite:', $blade);
        self::assertStringContainsString('runsBySite: {},', $blade);
        self::assertStringContainsString('siteState(siteId = null)', $blade);
        self::assertStringContainsString('get generating()', $blade);
        self::assertStringContainsString('_pollTimers: {},', $blade);
        self::assertStringContainsString('startRunPolling(runId, siteId = null)', $blade);
        self::assertStringContainsString('reconcileAfterTimeout(siteId = null)', $blade);
    }
}
