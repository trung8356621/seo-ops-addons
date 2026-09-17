<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicSource;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicManualCreateService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMembershipMatcher;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMembershipReconcileService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicSeedResolver;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicSyntheticManualKeywordRepairService;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Support\TopicPhraseResolver;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordCanonicalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Topic Membership V2: no synthetic keyword, topic.source, broad rescan.
 */
final class TopicMembershipV2ContractTest extends TestCase
{
    public function test_manual_create_does_not_upsert_keyword(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicManualCreateService.php');
        self::assertStringNotContainsString('keywords->upsert', $src);
        self::assertStringNotContainsString('KeywordPersistenceService', $src);
        self::assertStringNotContainsString('ensureManualSeed', $src);
        self::assertStringContainsString('TopicSource::MANUAL', $src);
        self::assertStringContainsString('TopicMembershipReconcileService', $src);
        self::assertStringContainsString('LOWER(name) = ?', $src);
        self::assertDoesNotMatchRegularExpression('/\bcluster_key\b/', $src);
        self::assertTrue(class_exists(TopicManualCreateService::class));
    }

    public function test_topic_source_enum_and_migration_exist(): void
    {
        self::assertSame('manual', TopicSource::MANUAL);
        self::assertSame('auto', TopicSource::AUTO);
        $migration = dirname(__DIR__, 3).'/database/migrations/2026_09_18_120000_add_source_to_seo_topics.php';
        self::assertFileExists($migration);
        $src = (string) file_get_contents($migration);
        self::assertStringContainsString("source", $src);
        self::assertStringContainsString("'manual'", $src);
        self::assertStringContainsString("'auto'", $src);
        self::assertDoesNotMatchRegularExpression('/\bcluster_key\b/', $src);
    }

    public function test_seed_resolver_no_longer_loads_manual_keyword_seeds(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicSeedResolver.php');
        self::assertStringNotContainsString('manualSeeds', $src);
        self::assertStringContainsString('linkListSeeds', $src);
        self::assertStringContainsString('productCatSeeds', $src);
        self::assertStringContainsString('seo_topics.source=manual', $src);
    }

    public function test_recluster_preserves_manual_topics_and_uses_broad_candidates(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicReclusterService.php');
        self::assertStringContainsString('TopicSource::MANUAL', $src);
        self::assertStringContainsString('loadTopicCandidateKeywords', $src);
        self::assertStringContainsString('manualTopicIds', $src);
        self::assertStringContainsString('$this->reconcile->reconcile', $src);
        self::assertStringContainsString("TopicSource::AUTO", $src);
    }

    public function test_reconcile_uses_broad_candidate_pool_and_shared_matcher(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicMembershipReconcileService.php');
        self::assertStringContainsString('loadTopicCandidateKeywords', $src);
        self::assertStringContainsString('TopicMembershipMatcher', $src);
        self::assertStringNotContainsString('loadEligibleSeoKeywords', $src);
        self::assertTrue(class_exists(TopicMembershipReconcileService::class));
    }

    public function test_candidate_loader_is_not_gated_on_is_seo_keyword(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicSiteKeywordService.php');
        self::assertStringContainsString('function loadTopicCandidateKeywords', $src);
        self::assertStringContainsString('forSite($siteId)', $src);
        self::assertStringContainsString('HideKeywordFromSeoService', $src);
        $eligiblePos = strpos($src, 'function loadEligibleSeoKeywords');
        $candidatePos = strpos($src, 'function loadTopicCandidateKeywords');
        self::assertNotFalse($eligiblePos);
        self::assertNotFalse($candidatePos);
        $candidateBlock = substr($src, (int) $candidatePos, 800);
        self::assertStringNotContainsString("where('is_seo_keyword', true)", $candidateBlock);
    }

    public function test_tag_metrics_prefer_topic_entity_source(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicTagMetricsResolver.php');
        self::assertStringContainsString('TopicSource::normalize', $src);
        self::assertStringContainsString("->get(['id', 'source'])", $src);
        self::assertStringNotContainsString('seedSourcesByTopic', $src);
    }

    public function test_rescan_button_wired_on_topic_detail(): void
    {
        $page = (string) file_get_contents(dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusterDetail.php');
        $blade = (string) file_get_contents(dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-detail.blade.php');
        $en = (string) file_get_contents(dirname(__DIR__, 4).'/seo-content-ai-compat/lang/en/filament.php');
        $vi = (string) file_get_contents(dirname(__DIR__, 4).'/seo-content-ai-compat/lang/vi/filament.php');
        self::assertStringContainsString('rescanTopicKeywords', $page);
        self::assertStringContainsString('wire:click="rescanTopicKeywords"', $blade);
        self::assertStringContainsString('topic_rescan_action', $en);
        self::assertStringContainsString('Dò lại từ khóa', $vi);
        self::assertStringContainsString('topic_rescan_success_body', $en);
    }

    public function test_matcher_still_shared_and_deterministic(): void
    {
        $matcher = new TopicMembershipMatcher(
            new TopicPhraseResolver(new KeywordNormalizer, new KeywordCanonicalizer),
        );
        self::assertTrue($matcher->matches('Balo anh văn việt plus', 'Balo anh văn'));
        self::assertTrue($matcher->matches('Xưởng may balo anh văn hợp phát', 'Balo anh văn'));
        self::assertFalse($matcher->matches('túi đựng mỹ phẩm', 'Balo anh văn'));
        self::assertTrue(class_exists(TopicMembershipMatcher::class));
        self::assertTrue(class_exists(TopicSeedResolver::class));
        self::assertTrue(class_exists(TopicSyntheticManualKeywordRepairService::class));
    }

    public function test_repair_service_is_evidence_gated(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicSyntheticManualKeywordRepairService.php');
        self::assertStringContainsString('hasRealEvidence', $src);
        self::assertStringContainsString('keywordUnusedEverywhere', $src);
        self::assertStringContainsString('mainArticles', $src);
        self::assertStringContainsString('SeoLinkMap', $src);
        self::assertStringContainsString('TopicSource::MANUAL', $src);
        self::assertDoesNotMatchRegularExpression('/\bcluster_key\b/', $src);
    }

    public function test_no_cluster_key_runtime_in_v2_files(): void
    {
        $files = [
            dirname(__DIR__, 3).'/src/Services/Topic/TopicManualCreateService.php',
            dirname(__DIR__, 3).'/src/Services/Topic/TopicMembershipReconcileService.php',
            dirname(__DIR__, 3).'/src/Services/Topic/TopicReclusterService.php',
            dirname(__DIR__, 3).'/src/Services/Topic/TopicSeedResolver.php',
            dirname(__DIR__, 3).'/src/Services/Topic/TopicSyntheticManualKeywordRepairService.php',
            dirname(__DIR__, 3).'/src/Enums/Topic/TopicSource.php',
        ];
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression('/\bcluster_key\b/', $src, basename($file));
            self::assertStringNotContainsString('CreateManualTopicClusterService', $src, basename($file));
            self::assertStringNotContainsString('seo_topic_cluster_meta', $src, basename($file));
        }
    }
}
