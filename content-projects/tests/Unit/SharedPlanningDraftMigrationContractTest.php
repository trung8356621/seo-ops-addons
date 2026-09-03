<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\CreateContentProjectHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectDraftPlanningItemsReadModel;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\LegacyPlanningDraftMergeService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftIntakeService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\PlanningDraftResolver;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectMonthlyWorkloadService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Shared Planning Draft migration + SEO Audit UI contracts.
 */
final class SharedPlanningDraftMigrationContractTest extends TestCase
{
    public function test_resolver_uses_unscoped_shared_draft_only(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(PlanningDraftResolver::class))->getFileName(),
        );

        self::assertStringContainsString('SeoProject::query()', $src);
        self::assertStringContainsString('findCanonicalSharedDraft', $src);
        self::assertStringContainsString('listLegacyPerSiteDrafts', $src);
        self::assertStringContainsString('findLegacyPlanningDraftForSite', $src);
        self::assertStringNotContainsString('getRecordRouteBindingEloquentQuery', $src);
        self::assertStringContainsString('return $this->findCanonicalSharedDraft()', $src);
    }

    public function test_merge_service_site_aware_dedupe_and_archive(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(LegacyPlanningDraftMergeService::class))->getFileName(),
        );

        self::assertStringContainsString('function dedupeKey', $src);
        self::assertStringContainsString('article', $src);
        self::assertStringContainsString('Identity-based', $src);
        self::assertStringContainsString('listLegacyDraftsWithRemainingItems', $src);
        self::assertStringContainsString('archiveLegacyDraft', $src);
        self::assertStringContainsString('skipped_duplicates', $src);
        self::assertStringNotContainsString("'source:'.", $src);
    }

    public function test_create_handler_rejects_per_site_draft_intent(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(CreateContentProjectHandler::class))->getFileName(),
        );

        self::assertStringContainsString('content_project.legacy_site_draft_creation_rejected', $src);
        self::assertStringContainsString('findCanonicalSharedDraft', $src);
        self::assertStringContainsString("'site_id' => \$status === SeoProject::STATUS_DRAFT ? null", $src);
    }

    public function test_seo_audit_header_has_no_create_draft_or_per_site_empty(): void
    {
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-seo-audit-planner.blade.php');
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );

        self::assertStringNotContainsString('content_planning_working_site', $blade);
        self::assertStringNotContainsString('content_planning_draft_label', $blade);
        self::assertStringNotContainsString('data-shared-planning-draft', $blade);
        self::assertStringNotContainsString('content_planning_no_draft_yet', $blade);
        self::assertStringNotContainsString('data-content-planning-action="create-draft"', $blade);
        self::assertStringNotContainsString('seo_audit_create_draft', $blade);
        self::assertStringContainsString('autoSelectSharedDraftIfNeeded', $page);
        self::assertStringContainsString('ensureSharedDraft', $page);
        self::assertStringContainsString('PlanningDraftIntakeService', $page);
        self::assertStringNotContainsString('autoSelectSiteDraftIfNeeded', $page);
        self::assertStringNotContainsString('findPlanningDraftForSite($siteId)', $page);
    }

    public function test_changing_working_site_keeps_shared_draft(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );

        self::assertStringContainsString('Working site is data context only', $page);
        self::assertStringContainsString('Shared Draft id must stay the same', $page);
        self::assertStringNotContainsString('$this->projectId = null;', substr(
            $page,
            (int) strpos($page, 'function updatedFilterSiteId'),
            800,
        ));
    }

    public function test_intake_ensure_shared_and_domain_column_recovery(): void
    {
        $intake = (string) file_get_contents(
            (string) (new ReflectionClass(PlanningDraftIntakeService::class))->getFileName(),
        );
        $readModel = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectDraftPlanningItemsReadModel::class))->getFileName(),
        );

        self::assertStringContainsString('function ensureSharedDraft', $intake);
        self::assertStringContainsString('whereNull(\'site_id\')', $intake);
        self::assertStringContainsString('recoverMissingItemSiteId', $readModel);
        self::assertStringContainsString('never invent a site', strtolower($readModel));
    }

    public function test_monthly_workload_still_excludes_draft(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectMonthlyWorkloadService::class))->getFileName(),
        );

        self::assertStringContainsString("where('p.status', '!=', SeoProject::STATUS_DRAFT)", $src);
    }

    public function test_component_blades_drop_create_draft_selector(): void
    {
        $seo = LegacyAddonPath::read('resources/views/components/content-project-seo-audit-planner.blade.php');
        $new = LegacyAddonPath::read('resources/views/components/content-project-new-content-planner.blade.php');

        self::assertStringNotContainsString('seo_audit_create_draft', $seo);
        self::assertStringNotContainsString('seo_audit_create_draft', $new);
        self::assertStringContainsString('data-shared-planning-draft', $seo);
        self::assertStringContainsString('data-shared-planning-draft', $new);
    }
}
