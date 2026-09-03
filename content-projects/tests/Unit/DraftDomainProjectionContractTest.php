<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithDraftSplit;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectDraftPlanningItemsReadModel;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\SplitDraftContentProjectService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

/**
 * Shared Draft stays global; Draft LIST VIEW is projected by ?draft_domain=.
 * Header + review counts + rows share the same domain-scoped read model.
 */
final class DraftDomainProjectionContractTest extends TestCase
{
    public function test_read_model_applies_domain_before_counts_and_row_filters(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectDraftPlanningItemsReadModel::class))->getFileName(),
        );

        self::assertStringContainsString("domain?: string", $src);
        self::assertStringContainsString('normalizeDomainFilter', $src);
        self::assertStringContainsString('applyDomainScopeToQuery', $src);
        self::assertStringContainsString('rowMatchesDomain', $src);
        self::assertMatchesRegularExpression(
            '/applyDomainScopeToQuery[\s\S]*?\$counts\s*=\s*\[/',
            $src,
        );
        self::assertMatchesRegularExpression(
            '/\$counts\s*=\s*\[[\s\S]*?\$reviewFilter/',
            $src,
        );
    }

    public function test_planner_passes_draft_domain_into_read_model(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );

        self::assertStringContainsString("'domain' => \$this->draftDomainFilter", $page);
        self::assertStringContainsString('draftPlanningRefreshNonce++', $page);
        self::assertStringContainsString('function setDraftDomainFilter', $page);
        self::assertDoesNotMatchRegularExpression(
            '/#\[Renderless\][^\n]*\n\s*public function setDraftDomainFilter/',
            $page,
        );
    }

    public function test_domain_selector_lives_in_draft_header_not_lower_filters(): void
    {
        $items = LegacyAddonPath::read('resources/views/components/content-project-draft-items.blade.php');

        self::assertStringContainsString('cp-plan-draft-header__domain', $items);
        self::assertStringContainsString('data-draft-domain-filter="1"', $items);
        self::assertStringNotContainsString('cp-plan-draft__domain-filter', $items);
        self::assertMatchesRegularExpression(
            '/cp-plan-draft-header[\s\S]*?data-draft-domain-filter="1"[\s\S]*?cp-plan-draft__tabs/',
            $items,
        );
        self::assertMatchesRegularExpression(
            '/cp-plan-draft__filters-row[\s\S]*?data-draft-type-filter="1"/',
            $items,
        );
        self::assertDoesNotMatchRegularExpression(
            '/cp-plan-draft__filters-row[\s\S]*?data-draft-domain-filter/',
            $items,
        );
    }

    public function test_wire_key_includes_draft_domain_for_remount(): void
    {
        $items = LegacyAddonPath::read('resources/views/components/content-project-draft-items.blade.php');

        self::assertStringContainsString(
            'wire:key="cp-draft-items-{{ $reviewFilter }}-{{ $typeFilter }}-{{ $draftDomainFilter }}-{{ (int) $refreshNonce }}"',
            $items,
        );
        self::assertStringContainsString('rowMatchesDomainProjection', $items);
        self::assertStringContainsString('this.removeLocal(row.id)', $items);
        self::assertStringContainsString('setDraftDomainFilter', $items);
        self::assertStringContainsString('restoreLocal(snapshot)', $items);
        self::assertStringContainsString('// Optimistic: drop from list immediately, then persist.', $items);
    }

    public function test_publish_respects_draft_domain_scope(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(InteractsWithDraftSplit::class))->getFileName(),
        );

        self::assertStringContainsString('resolvePublishDraftSiteScope', $src);
        self::assertStringContainsString('resolvePublishEligibleTaskIds', $src);
        self::assertStringContainsString('orderedReviewedDraftTaskIds($project, $this->resolvePublishDraftSiteScope())', $src);
        self::assertStringContainsString('currentReviewedDraftItemCount($project, $siteScope)', $src);
        self::assertStringContainsString('MODE_SELECTED', $src);
    }

    public function test_split_service_accepts_optional_site_scope(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SplitDraftContentProjectService::class))->getFileName(),
        );

        self::assertStringContainsString('function orderedReviewedDraftTaskIds(SeoProject $draft, ?int $siteId = null)', $src);
        self::assertStringContainsString('function currentReviewedDraftItemCount(SeoProject $draft, ?int $siteId = null)', $src);
        self::assertStringContainsString("\$query->where('site_id', \$siteId)", $src);
    }

    public function test_domain_column_remains_in_table(): void
    {
        $items = LegacyAddonPath::read('resources/views/components/content-project-draft-items.blade.php');

        self::assertStringContainsString('planning_col_domain', $items);
        self::assertStringContainsString('cp-plan-draft-table__col-domain', $items);
        self::assertStringContainsString('changeDomain(row', $items);
    }
}
