<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use Omnichannel\Addons\SearchFoundation\Services\SiteLink\VerifiedProductCatLinkSource;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicKeywordSource;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicSeedResolver;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Phase 2A: curated Domain Link List seeds; Site Sync catalog is not Topic seed evidence.
 */
final class TopicSeedResolverDomainLinkListTest extends TestCase
{
    public function test_resolver_reads_prompt_context_links_not_site_sync_catalog(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicSeedResolver.php');
        self::assertStringContainsString('SiteDomainPromptContextService', $src);
        self::assertStringContainsString('getRawPayloadForSite', $src);
        self::assertStringContainsString('normalizeCuratedLinkRows', $src);
        self::assertStringContainsString('VerifiedProductCatLinkSource', $src);
        self::assertStringNotContainsString('SiteLinkCatalogCapability', $src);
        self::assertStringNotContainsString('effectiveLinks', $src);
        self::assertStringNotContainsString('->forKeyword(', $src);
        self::assertStringNotContainsString('::forKeyword(', $src);
        self::assertStringNotContainsString('forArticleEditor(', $src);
        self::assertStringNotContainsString('phraseFromLinkRow', $src);
        self::assertStringNotContainsString('focus_keyword', $src);
        self::assertSame(TopicKeywordSource::LINK_LIST, 'link_list');
        self::assertSame(TopicKeywordSource::PRODUCT_CAT, 'product_cat');
    }

    public function test_constructor_depends_on_canonical_source_components(): void
    {
        $ref = new ReflectionClass(TopicSeedResolver::class);
        $params = $ref->getConstructor()?->getParameters() ?? [];
        $types = array_map(
            static fn ($p): string => (string) ($p->getType()?->__toString() ?? ''),
            $params,
        );
        self::assertContains(SiteDomainPromptContextService::class, $types);
        self::assertContains(VerifiedProductCatLinkSource::class, $types);
    }

    public function test_curated_domain_link_row_becomes_seed_phrase(): void
    {
        $rows = TopicSeedResolver::normalizeCuratedLinkRows([
            ['keyword' => 'Balo quà tặng', 'link' => 'https://example.test/balo'],
            ['keyword' => '  Túi giữ nhiệt  ', 'link' => 'https://example.test/tui'],
        ]);

        self::assertCount(2, $rows);
        self::assertSame('https://example.test/balo', $rows[0]['url']);
        self::assertNotSame('', $rows[0]['keyword']);
        self::assertSame('https://example.test/tui', $rows[1]['url']);
    }

    public function test_wp_catalog_chrome_without_curated_evidence_is_not_seed(): void
    {
        $rows = TopicSeedResolver::normalizeCuratedLinkRows([
            // Inventory-style rows (title only / missing keyword+url) must not seed Topics.
            ['title' => 'Cart', 'url' => 'https://example.test/cart'],
            ['title' => 'Footer', 'slug' => 'footer'],
            ['title' => 'Liên hệ', 'canonical' => 'https://example.test/contact'],
            ['keyword' => 'Cart', 'link' => ''], // keyword without URL
            ['keyword' => '', 'link' => 'https://example.test/footer'],
            ['focus_keyword' => 'random WP article title', 'url' => 'https://example.test/post'],
        ]);

        self::assertSame([], $rows);
    }

    public function test_duplicate_curated_keywords_dedupe(): void
    {
        $rows = TopicSeedResolver::normalizeCuratedLinkRows([
            ['keyword' => 'Balo quà tặng', 'link' => 'https://example.test/a'],
            ['keyword' => 'balo quà tặng', 'link' => 'https://example.test/b'],
        ]);
        self::assertCount(1, $rows);
        self::assertSame('https://example.test/a', $rows[0]['url']);
    }

    public function test_preview_seed_evidence_is_read_only_contract(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicSeedResolver.php');
        self::assertStringContainsString('function previewSeedEvidence', $src);
        self::assertStringContainsString('read_only_preview_no_topic_mutation', $src);
        self::assertStringContainsString('new_curated_link_seeds', $src);
        self::assertStringContainsString('estimated_stale_auto_topics', $src);
        $fromPreview = substr($src, (int) strpos($src, 'function previewSeedEvidence'), 3500);
        self::assertStringContainsString('curatedDomainLinkRows', $fromPreview);
        self::assertStringContainsString('productCatSeedRows', $fromPreview);
        self::assertStringNotContainsString('->upsert(', $fromPreview);
    }

    public function test_link_list_still_precedes_product_cat_in_resolve(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicSeedResolver.php');
        $linkPos = strpos($src, 'linkListSeeds');
        $catPos = strpos($src, 'productCatSeeds');
        self::assertNotFalse($linkPos);
        self::assertNotFalse($catPos);
        self::assertLessThan((int) $catPos, (int) $linkPos);
        self::assertStringContainsString("if (! isset(\$byKeyword[\$seed['keyword_id']]))", $src);
    }

    public function test_preview_command_registered_and_not_recluster(): void
    {
        $cmd = (string) file_get_contents(dirname(__DIR__, 3).'/src/Console/PreviewTopicSeedEvidenceCommand.php');
        $provider = (string) file_get_contents(dirname(__DIR__, 3).'/src/SearchIntelligenceServiceProvider.php');
        self::assertStringContainsString('seo:topics-seed-preview', $cmd);
        self::assertStringContainsString('previewSeedEvidence', $cmd);
        self::assertStringNotContainsString('->recluster(', $cmd);
        self::assertStringContainsString('PreviewTopicSeedEvidenceCommand', $provider);
    }

    public function test_docs_state_catalog_is_not_topic_seed(): void
    {
        $topicDoc = (string) file_get_contents(dirname(__DIR__, 4).'/docs/modules/TOPIC_CORE.md');
        self::assertStringContainsString('curated Domain Link List', $topicDoc);
        self::assertTrue(
            str_contains($topicDoc, 'NOT** automatic Topic seed evidence')
            || str_contains($topicDoc, 'NOT automatic Topic seed evidence'),
        );
        self::assertStringContainsString('Membership candidate pool', $topicDoc);
        self::assertStringContainsString('forKeyword()', $topicDoc); // documents that Topic does NOT call it
        self::assertStringContainsString('does **not** call `SiteLinkPolicyResolver::forKeyword()`', $topicDoc);
    }
}
