<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithSeoAuditSuggestions;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\AddSeoAuditSuggestionsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\FillSeoAuditSuggestionsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\AddSeoAuditSuggestionsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\FillSeoAuditSuggestionsHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit\SeoAuditExistingContentSuggestionService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit\SeoAuditSuggestionDecisionService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit\SeoAuditSuggestionPlannerService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Domain-neutral Shared Draft + explicit working Site for SEO Audit Existing Content.
 */
final class SharedDraftSeoAuditSiteContextTest extends TestCase
{
    public function test_commands_require_explicit_site_id(): void
    {
        $fill = new FillSeoAuditSuggestionsCommand(42, 6, [], 20);
        self::assertSame(42, $fill->projectRef);
        self::assertSame(6, $fill->siteId);

        $add = new AddSeoAuditSuggestionsCommand(42, 6, []);
        self::assertSame(6, $add->siteId);

        $fillHandler = (string) file_get_contents(
            (string) (new ReflectionClass(FillSeoAuditSuggestionsHandler::class))->getFileName(),
        );
        self::assertStringContainsString('Working site is required.', $fillHandler);
        self::assertStringContainsString('assertCanAccessSite($siteId, $actor)', $fillHandler);
        self::assertStringContainsString('fillSuggestions(', $fillHandler);
        self::assertStringContainsString('$site,', $fillHandler);
        self::assertStringContainsString('SUGGESTIONS_NONE_ADDED', $fillHandler);

        $addHandler = (string) file_get_contents(
            (string) (new ReflectionClass(AddSeoAuditSuggestionsHandler::class))->getFileName(),
        );
        self::assertStringContainsString('addToDraftProject(', $addHandler);
        self::assertStringContainsString('$site,', $addHandler);
        self::assertStringContainsString('SUGGESTIONS_NONE_ADDED', $addHandler);
    }

    public function test_suggestion_read_path_uses_explicit_site_not_project_site(): void
    {
        $svc = (string) file_get_contents(
            (string) (new ReflectionClass(SeoAuditExistingContentSuggestionService::class))->getFileName(),
        );
        self::assertStringContainsString('Site $site', $svc);
        self::assertStringContainsString("where('articles.site_id', \$siteId)", $svc);
        self::assertStringNotContainsString('$project->site_id ?? 0', $svc);

        $planner = (string) file_get_contents(
            (string) (new ReflectionClass(SeoAuditSuggestionPlannerService::class))->getFileName(),
        );
        self::assertStringContainsString('Site $site', $planner);
        self::assertStringContainsString('addToDraftProject(', $planner);
        self::assertStringContainsString('fillSuggestions(', $planner);
        self::assertStringNotContainsString('$siteId = (int) ($project->site_id ?? 0);', $planner);
    }

    public function test_trait_wires_working_site_for_planner(): void
    {
        $trait = (string) file_get_contents(
            (string) (new ReflectionClass(InteractsWithSeoAuditSuggestions::class))->getFileName(),
        );
        self::assertStringContainsString('resolveSeoAuditWorkingSite', $trait);
        self::assertStringContainsString('resolvePlanningSite', $trait);
        self::assertStringContainsString('new FillSeoAuditSuggestionsCommand(', $trait);
        self::assertStringContainsString('$workingSiteId', $trait);
        self::assertStringContainsString('new AddSeoAuditSuggestionsCommand(', $trait);
        self::assertStringContainsString('refreshDraftPlanningSnapshotAfterMutation', $trait);
        self::assertStringContainsString('SUGGESTIONS_NONE_ADDED', $trait);
        self::assertStringNotContainsString(
            "new FillSeoAuditSuggestionsCommand(\n            (int) \$project->getKey(),\n            \$this->buildSuggestionFilters()",
            $trait,
        );
    }

    public function test_decision_service_accepts_explicit_site_id(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SeoAuditSuggestionDecisionService::class))->getFileName(),
        );
        self::assertStringContainsString('?int $siteId = null', $src);
        self::assertStringContainsString('$resolvedSiteId = $siteId !== null && $siteId > 0', $src);
    }

    public function test_page_exposes_refresh_draft_snapshot_helper(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );
        self::assertStringContainsString('function refreshDraftPlanningSnapshot', $page);
        self::assertStringContainsString('draftDomainFilter', $page);
        self::assertStringContainsString('function resolvePlanningSite', $page);
    }

    public function test_none_added_action_code_exists(): void
    {
        self::assertSame('suggestions.none_added', ContentProjectActionCodes::SUGGESTIONS_NONE_ADDED);
    }
}
