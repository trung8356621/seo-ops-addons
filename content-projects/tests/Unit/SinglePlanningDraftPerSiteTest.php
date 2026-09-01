<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\CreateContentProjectHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftResolver;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

final class SinglePlanningDraftPerSiteTest extends TestCase
{
    public function test_resolver_finds_canonical_draft_for_site(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(PlanningDraftResolver::class))->getFileName(),
        );

        self::assertStringContainsString('findPlanningDraftForSite', $src);
        self::assertStringContainsString('findCanonicalSharedDraft', $src);
        self::assertStringContainsString('STATUS_DRAFT', $src);
        self::assertStringContainsString('isDraftPlanning()', $src);
        self::assertStringContainsString('return $this->findCanonicalSharedDraft()', $src);
    }

    public function test_create_handler_reuses_existing_planning_draft(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(CreateContentProjectHandler::class))->getFileName(),
        );

        self::assertStringContainsString('PlanningDraftResolver', $src);
        self::assertStringContainsString('findCanonicalSharedDraft', $src);
        self::assertStringContainsString('reused_existing_draft', $src);
        self::assertStringContainsString('STATUS_DRAFT', $src);
        self::assertStringContainsString('legacy_site_draft_creation_rejected', $src);
        self::assertStringNotContainsString('findPlanningDraftForSite($siteId)', $src);
    }

    public function test_planner_ui_has_no_create_another_draft_when_draft_exists(): void
    {
        $page = LegacyAddonPath::read('resources/views/filament/pages/content-project-seo-audit-planner.blade.php');
        $planner = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Filament/Pages/ContentProjectSeoAuditPlanner.php',
        );

        self::assertStringContainsString('data-planning-draft-display', $page);
        self::assertStringContainsString('data-shared-planning-draft', $page);
        self::assertStringContainsString('PlanningDraftResolver', $planner);
        self::assertStringContainsString('autoSelectSharedDraftIfNeeded', $planner);
        self::assertStringNotContainsString('Create another Draft', $page);
        self::assertStringNotContainsString('content_planning_no_draft_yet', $page);
        self::assertStringNotContainsString('seo_audit_create_draft', $page);
    }
}
