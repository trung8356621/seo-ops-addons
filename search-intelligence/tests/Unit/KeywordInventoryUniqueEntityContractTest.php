<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Documents inventory semantics: counters are unique keyword entities (keywords.id),
 * never multiplied by seo_link_maps / anchor occurrences.
 */
final class KeywordInventoryUniqueEntityContractTest extends TestCase
{
    public function test_dictionary_stats_count_keyword_query_not_link_maps(): void
    {
        $list = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Filament/Resources/KeywordResource/Pages/ListKeywords.php');

        $statsPos = strpos($list, 'function getDictionaryStats');
        $this->assertNotFalse($statsPos);
        $chunk = substr($list, $statsPos, 1200);
        $this->assertStringContainsString("->count()", $chunk);
        $this->assertStringNotContainsString('seo_link_maps', $chunk);
        $this->assertStringContainsString('buildDictionaryFilteredQuery', $chunk);
    }

    public function test_cluster_inventory_plucks_keyword_ids(): void
    {
        $scope = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Services/KeywordIntelligence/KeywordClusterSiteScope.php');
        $eligibility = (string) file_get_contents(dirname(__DIR__, 2)
            .'/src/Services/KeywordIntelligence/KeywordClusterEligibility.php');

        $this->assertStringContainsString("->pluck('id')", $scope);
        $this->assertStringContainsString('count($keywordIds)', $eligibility);
        $this->assertStringContainsString("whereIn('keyword_id', \$keywordIds)", $eligibility);
    }

    public function test_keyword_persistence_upserts_by_phrase_identity(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3)
            .'/search-foundation/src/Services/KeywordPersistenceService.php');

        $this->assertStringContainsString("phrase COLLATE utf8mb4_unicode_ci = ?", $src);
        $this->assertStringContainsString('->first()', $src);
    }
}
