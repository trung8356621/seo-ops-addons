<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithNewContentSuggestions;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\GenerateNewContentSuggestionsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\GenerateNewContentSuggestionsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentGenerationReadinessService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionOptions;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\NewContentSuggestionPlannerService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner\ContentProjectPlannerRunService;
use App\Models\Site;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Domain-neutral Shared Draft + explicit working Site for AI New Content.
 */
final class SharedDraftNewContentSiteContextTest extends TestCase
{
    public function test_readiness_evaluate_accepts_explicit_working_site(): void
    {
        $method = new ReflectionMethod(NewContentGenerationReadinessService::class, 'evaluate');
        $params = $method->getParameters();
        self::assertGreaterThanOrEqual(2, count($params));
        self::assertSame('site', $params[1]->getName());

        $src = (string) file_get_contents(
            (string) (new ReflectionClass(NewContentGenerationReadinessService::class))->getFileName(),
        );
        self::assertStringContainsString('resolveLanguageSite', $src);
        self::assertStringContainsString('explicitSite', $src);
        self::assertStringContainsString('$project->site_id', $src);
    }

    public function test_planner_resolve_planning_site_uses_filter_not_project_site(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );
        self::assertStringContainsString('function resolvePlanningSite', $page);
        self::assertStringContainsString('filterSiteId', $page);
        self::assertStringContainsString('canAccessSite($siteId)', $page);

        $trait = (string) file_get_contents(
            (string) (new ReflectionClass(InteractsWithNewContentSuggestions::class))->getFileName(),
        );
        self::assertStringContainsString('resolveNewContentWorkingSite', $trait);
        self::assertStringContainsString('resolvePlanningSite', $trait);
        self::assertStringContainsString('resolveNewContentWorkingSite()', $trait);
        self::assertStringContainsString('new GenerateNewContentSuggestionsCommand(', $trait);
        self::assertStringContainsString('$workingSiteId', $trait);
        self::assertStringNotContainsString(
            "new GenerateNewContentSuggestionsCommand(\n                    (int) \$project->getKey(),\n                    \$options['quantity']",
            $trait,
        );
    }

    public function test_command_requires_explicit_site_id(): void
    {
        $cmd = new GenerateNewContentSuggestionsCommand(42, 6, 20);
        self::assertSame(42, $cmd->projectRef);
        self::assertSame(6, $cmd->siteId);
        self::assertSame(20, $cmd->quantity);

        $handler = (string) file_get_contents(
            (string) (new ReflectionClass(GenerateNewContentSuggestionsHandler::class))->getFileName(),
        );
        self::assertStringContainsString('Working site is required.', $handler);
        self::assertStringContainsString('assertCanAccessSite($siteId, $actor)', $handler);
        self::assertStringContainsString('PRIMARY_LANGUAGE_MISSING', $handler);
        self::assertStringContainsString("'site_id' => \$siteId", $handler);
        self::assertStringNotContainsString('Project domain is required.', $handler);
        self::assertStringNotContainsString('(int) ($project->site_id ?? 0)', $handler);
    }

    public function test_snapshot_and_planner_persist_working_site(): void
    {
        $snapshot = NewContentSuggestionOptions::snapshot(['quantity' => 10], 'vi', 6);
        self::assertSame(6, $snapshot['site_id']);
        self::assertSame('vi', $snapshot['primary_language']);

        $planner = (string) file_get_contents(
            (string) (new ReflectionClass(NewContentSuggestionPlannerService::class))->getFileName(),
        );
        self::assertStringContainsString('queueGeneration(SeoProject $project, Site $site', $planner);
        self::assertStringContainsString('generateNow(SeoProject $project, Site $site', $planner);
        self::assertStringContainsString('resolveSiteForRun', $planner);
        self::assertStringContainsString("'site_id' => \$workingSiteId", $planner);
        self::assertStringContainsString('snapshot($normalized, $language, (int) $site->getKey())', $planner);
        self::assertStringNotContainsString(
            "'site_id' => (int) (\$target->site_id ?? \$project->site_id ?? 0)",
            $planner,
        );
        self::assertStringNotContainsString('private function resolveSite(SeoProject $project)', $planner);

        $runs = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectPlannerRunService::class))->getFileName(),
        );
        self::assertStringContainsString('resolveRunSiteId', $runs);
        self::assertStringContainsString("\$configurationSnapshot['site_id']", $runs);
    }

    public function test_tenant_guard_allows_shared_draft_and_guards_site_separately(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectTenantGuard::class))->getFileName(),
        );
        self::assertStringContainsString('isDraftPlanning()', $src);
        self::assertStringContainsString('function assertCanAccessSite', $src);
        self::assertStringContainsString('Shared / domain-neutral Draft', $src);

        $project = new SeoProject;
        $project->status = SeoProject::STATUS_DRAFT;
        $project->site_id = null;
        self::assertTrue($project->isDraftPlanning());
        self::assertSame(0, (int) ($project->site_id ?? 0));
    }

    public function test_import_prefers_snapshot_site_over_project(): void
    {
        $planner = (string) file_get_contents(
            (string) (new ReflectionClass(NewContentSuggestionPlannerService::class))->getFileName(),
        );
        $start = strpos($planner, 'function importFromExistingRun');
        self::assertNotFalse($start);
        $chunk = substr($planner, $start, 2500);
        self::assertStringContainsString('resolveSiteForRun($project, $snapshot)', $chunk);
        self::assertStringNotContainsString('resolveSite($project)', $chunk);

        $resolveStart = strpos($planner, 'function resolveSiteForRun');
        self::assertNotFalse($resolveStart);
        $resolveChunk = substr($planner, $resolveStart, 900);
        self::assertStringContainsString("\$snapshot['site_id']", $resolveChunk);
        self::assertStringContainsString('$project->site_id', $resolveChunk);
        self::assertStringContainsString('Historical planner run has no site_id snapshot', $resolveChunk);
    }

    public function test_site_model_key_contract_for_working_site(): void
    {
        $site = new Site;
        $site->id = 6;
        self::assertSame(6, (int) $site->getKey());
    }
}
