<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\LegacyAddonPath;

/**
 * Draft list Domain filter persists via ?draft_domain= — independent of Working Site ?site=.
 */
final class DraftDomainFilterUrlContractTest extends TestCase
{
    public function test_default_draft_domain_filter_is_all_with_url_except(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );

        self::assertStringContainsString("#[Url(as: 'draft_domain', except: 'all')]", $page);
        self::assertStringContainsString("public string \$draftDomainFilter = 'all';", $page);
        self::assertStringNotContainsString('public int $draftDomainFilter', $page);
        self::assertStringContainsString('function setDraftDomainFilter', $page);
        self::assertStringContainsString('draftPlanningRefreshNonce++', $page);
        self::assertStringContainsString('normalizeDraftDomainFilter', $page);
        self::assertDoesNotMatchRegularExpression(
            '/#\[Renderless\][^\n]*\n\s*public function setDraftDomainFilter/',
            $page,
        );
    }

    public function test_normalize_allows_all_zero_and_digit_site_ids(): void
    {
        $method = new ReflectionMethod(ContentProjectSeoAuditPlanner::class, 'normalizeDraftDomainFilter');
        $method->setAccessible(true);
        $planner = (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->newInstanceWithoutConstructor();

        self::assertSame('all', $method->invoke($planner, 'all'));
        self::assertSame('all', $method->invoke($planner, ''));
        self::assertSame('all', $method->invoke($planner, 'ALL'));
        self::assertSame('0', $method->invoke($planner, '0'));
        self::assertSame('all', $method->invoke($planner, 'abc'));
        self::assertSame('all', $method->invoke($planner, '-1'));
    }

    public function test_boot_payload_uses_draft_domain_filter_prop_not_hardcoded_all(): void
    {
        $items = LegacyAddonPath::read('resources/views/components/content-project-draft-items.blade.php');
        $blade = LegacyAddonPath::read('resources/views/filament/pages/content-project-seo-audit-planner.blade.php');

        self::assertStringContainsString("'draftDomainFilter' => 'all'", $items);
        self::assertStringContainsString("'domainFilter' => (string) \$draftDomainFilter,", $items);
        self::assertStringNotContainsString("'domainFilter' => 'all',", $items);
        self::assertStringContainsString(':draft-domain-filter="$this->draftDomainFilter"', $blade);
    }

    public function test_alpine_set_domain_filter_persists_through_livewire(): void
    {
        $items = LegacyAddonPath::read('resources/views/components/content-project-draft-items.blade.php');

        self::assertStringContainsString('setDomainFilter(next)', $items);
        self::assertStringContainsString('this.$wire.setDraftDomainFilter(value)', $items);
        self::assertStringContainsString('rowMatchesDomainProjection', $items);
        // x-model races @change (updates Alpine first → early-return skips Livewire).
        self::assertStringNotContainsString('x-model="domainFilter"', $items);
        self::assertStringContainsString(':value="domainFilter"', $items);
    }

    public function test_remount_keys_include_domain_filter_and_boot_restores_it(): void
    {
        $items = LegacyAddonPath::read('resources/views/components/content-project-draft-items.blade.php');

        self::assertStringContainsString(
            'wire:key="cp-draft-items-{{ $reviewFilter }}-{{ $typeFilter }}-{{ $draftDomainFilter }}-{{ (int) $refreshNonce }}"',
            $items,
        );
        self::assertStringContainsString("'domainFilter' => (string) \$draftDomainFilter,", $items);
        self::assertStringContainsString('cpPlanDraftItems(@js($boot))', $items);
    }

    public function test_site_and_draft_domain_remain_independent(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );

        self::assertStringNotContainsString("#[Url(as: 'site')]", $page);
        self::assertStringContainsString('public ?int $filterSiteId = null;', $page);
        self::assertStringContainsString("#[Url(as: 'draft_domain', except: 'all')]", $page);
        self::assertStringContainsString('applyWorkingSiteContext', $page);
        self::assertStringContainsString('SITE_ID_QUERY_KEY', $page);
        self::assertStringContainsString(
            'Draft Domain filter stays independent unless the referenced site becomes inaccessible',
            $page,
        );
        self::assertStringContainsString(
            '$this->draftDomainFilter = $this->normalizeDraftDomainFilter($this->draftDomainFilter);',
            $page,
        );
        self::assertStringContainsString("\$params['draft_domain'] = \$draftDomain;", $page);
    }

    public function test_domain_filter_select_boots_selected_from_draft_domain(): void
    {
        $items = LegacyAddonPath::read('resources/views/components/content-project-draft-items.blade.php');

        self::assertStringContainsString('data-draft-domain-filter="1"', $items);
        self::assertStringContainsString('cp-plan-draft-header__domain', $items);
        self::assertMatchesRegularExpression(
            '/data-draft-domain-filter="1"[\s\S]*?@foreach \(\$siteOptionsList as \$siteOpt\)/',
            $items,
        );
        self::assertStringContainsString('syncDomainFilterSelect()', $items);
    }

    public function test_inaccessible_draft_domain_normalizes_via_can_access_site(): void
    {
        $page = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectSeoAuditPlanner::class))->getFileName(),
        );

        self::assertMatchesRegularExpression(
            '/function normalizeDraftDomainFilter[\s\S]*?canAccessSite\(\$siteId\)[\s\S]*?return \'all\'/',
            $page,
        );
    }
}
