<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Tests\Unit\SiteLink;

use Omnichannel\Addons\SearchFoundation\Services\DomainLinkListKeywordSyncService;
use Omnichannel\Addons\Agent\Automation\Actions\Keyword\SyncKeywordDomainLinkListAction;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Architecture guards: policy materialization must not pollute prompt Domain Link List.
 */
final class DomainLinkListKeywordContaminationGuardTest extends TestCase
{
    public function test_sync_service_materializes_policy_not_prompt_only(): void
    {
        $ref = new ReflectionClass(DomainLinkListKeywordSyncService::class);
        $source = (string) file_get_contents((string) $ref->getFileName());

        self::assertStringContainsString('SiteLinkPolicyResolver', $source);
        self::assertStringContainsString('forKeyword', $source);
        self::assertStringContainsString('materializePolicyRecords', $source);
        self::assertStringContainsString('siteLinkPolicySource', $source);
        self::assertStringContainsString('SOURCE_PRODUCT_CAT', $source);
        self::assertStringContainsString('isProductCatPolicyKeyword', $source);
        // Manual prompt mutation methods must keep product_cat out.
        self::assertStringContainsString('upsertLinkInDomainContext', $source);
        self::assertMatchesRegularExpression(
            '/function upsertLinkInDomainContext[\s\S]*isProductCatPolicyKeyword/',
            $source,
        );
        // Never call saveForSite from materialize path with product_cat composition.
        self::assertStringNotContainsString('forArticleEditor', $source);
    }

    public function test_reverse_action_skips_product_cat_policy_keywords(): void
    {
        $ref = new ReflectionClass(SyncKeywordDomainLinkListAction::class);
        $source = (string) file_get_contents((string) $ref->getFileName());

        self::assertStringContainsString('isProductCatPolicyKeyword', $source);
        self::assertStringContainsString('skipped_product_cat_policy', $source);
    }
}
