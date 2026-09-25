<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchFoundation\Support\DomainListPresentation;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicNaming;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Support\TopicDnaExtractor;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Support\TopicPhraseResolver;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordCanonicalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Topic Core invariants that do not require a live DB.
 */
final class TopicCoreArchitectureTest extends TestCase
{
    public function test_website_type_manufacturer_maps_to_production_internal_key(): void
    {
        $options = DomainListPresentation::websiteTypeFormOptions();
        self::assertSame('Manufacturer', $options['production']);
        self::assertSame('Ecommerce', $options['e-commerce']);
        self::assertSame('News', $options['news']);
        self::assertSame('Manufacturer', DomainListPresentation::websiteTypeLabel('production'));
        self::assertNotSame('production', DomainListPresentation::websiteTypeLabel('production'));
    }

    public function test_topic_name_keeps_unicode_and_rejects_hash_style_labels(): void
    {
        $name = TopicNaming::canonicalName('Túi Đựng Mỹ Phẩm Hợp Phát');
        self::assertStringContainsString('Túi', $name);
        self::assertStringContainsString('Phẩm', $name);
        self::assertStringNotContainsString('__', $name);
        self::assertDoesNotMatchRegularExpression('/^[a-z0-9_]+__[a-f0-9]+$/', $name);
    }

    public function test_dna_extractor_returns_no_normalized_value_field(): void
    {
        $extractor = new TopicDnaExtractor(
            new KeywordNormalizer,
            new TopicPhraseResolver(new KeywordNormalizer, new KeywordCanonicalizer),
        );
        $rows = $extractor->extract('túi đựng mỹ phẩm du lịch', 'Túi đựng mỹ phẩm');
        foreach ($rows as $row) {
            self::assertArrayHasKey('value', $row);
            self::assertArrayNotHasKey('normalized_value', $row);
            self::assertArrayNotHasKey('folded_value', $row);
            self::assertArrayNotHasKey('cluster_key', $row);
        }
    }

    public function test_dna_extractor_preserves_semantic_glue_in_question_phrases(): void
    {
        $extractor = new TopicDnaExtractor(
            new KeywordNormalizer,
            new TopicPhraseResolver(new KeywordNormalizer, new KeywordCanonicalizer),
        );

        $whatRows = $extractor->extract('vải da PVC là gì', 'Vải da PVC');
        self::assertSame(['là gì'], array_column($whatRows, 'value'));
        self::assertSame(['after'], array_column($whatRows, 'placement'));

        $whereRows = $extractor->extract('vải da PVC ở đâu', 'Vải da PVC');
        self::assertSame(['ở đâu'], array_column($whereRows, 'value'));
    }

    public function test_migration_defines_four_tables_without_cluster_key(): void
    {
        $path = dirname(__DIR__, 3).'/database/migrations/2026_09_17_120000_create_seo_topic_core_tables.php';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('seo_site_keywords', $src);
        self::assertStringContainsString('seo_topics', $src);
        self::assertStringContainsString('seo_topic_keywords', $src);
        self::assertStringContainsString('seo_topic_keyword_dna', $src);
        self::assertStringNotContainsString('cluster_key', $src);
        self::assertStringNotContainsString('display_name', $src);
        self::assertStringNotContainsString('normalized_name', $src);
        self::assertStringNotContainsString('folded_name', $src);
        self::assertSame(1, preg_match(
            "/create\\('seo_site_keywords', function \\(Blueprint \\\$table\\): void \\{(.*?)\\}\\);/s",
            $src,
            $matches,
        ));
        self::assertStringNotContainsString('topic_id', $matches[1]);
    }

    public function test_no_labels_or_aliases_tables_in_topic_core_migration(): void
    {
        $path = dirname(__DIR__, 3).'/database/migrations/2026_09_17_120000_create_seo_topic_core_tables.php';
        $src = (string) file_get_contents($path);
        self::assertStringNotContainsString('seo_topic_labels', $src);
        self::assertStringNotContainsString('seo_topic_aliases', $src);
    }

    public function test_docs_document_manufacturer_production_mapping(): void
    {
        $path = dirname(__DIR__, 4).'/docs/modules/WEBSITE_TYPE.md';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('Manufacturer', $src);
        self::assertStringContainsString('production', $src);
        self::assertStringContainsString('e-commerce', $src);
        self::assertTrue(
            str_contains($src, 'Never infer') || str_contains($src, 'Do not rename'),
            'Docs must warn that production is not a UI label',
        );
    }

    public function test_recluster_service_mentions_no_focus_force_rule(): void
    {
        $path = dirname(__DIR__, 3).'/src/Services/Topic/TopicReclusterService.php';
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('Focus', $src);
        self::assertStringNotContainsString("'cluster_key'", $src);
        self::assertStringContainsString('siteId', $src);
    }

    public function test_seed_resolver_uses_curated_domain_link_list_not_catalog(): void
    {
        $path = dirname(__DIR__, 3).'/src/Services/Topic/TopicSeedResolver.php';
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('SiteDomainPromptContextService', $src);
        self::assertStringContainsString('VerifiedProductCatLinkSource', $src);
        self::assertStringContainsString('curatedDomainLinkRows', $src);
        self::assertStringContainsString('parent_term_id', $src);
        self::assertStringContainsString('verifiedProductCategories', $src);
        self::assertStringNotContainsString('SiteLinkCatalogCapability', $src);
        self::assertStringNotContainsString('effectiveLinks', $src);
        self::assertStringNotContainsString('rootProductCategories', $src);
        self::assertStringNotContainsString('->forKeyword(', $src);
        self::assertStringNotContainsString('::forKeyword(', $src);
        self::assertStringNotContainsString('DomainLinkListKeywordSyncService', $src);
    }

    public function test_linked_article_counter_requires_site_id(): void
    {
        $path = dirname(__DIR__, 3).'/src/Services/Topic/TopicLinkedArticleCounter.php';
        $src = (string) file_get_contents($path);
        self::assertStringContainsString("where('site_id', \$siteId)", $src);
        self::assertStringContainsString('keyword_meta', $src);
        self::assertStringContainsString('Focus Article', $src);
    }

    public function test_dissolve_preserves_site_keywords_table(): void
    {
        $path = dirname(__DIR__, 3).'/src/Services/Topic/TopicDissolveService.php';
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('SeoTopicKeywordDna', $src);
        self::assertStringContainsString('SeoTopicKeyword', $src);
        self::assertStringContainsString('SeoTopic', $src);
        self::assertStringNotContainsString('SeoSiteKeyword::', $src);
    }
}
