<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteClusterSuggestionQuery;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner\AuditNotePlannerExactTopicMatcher;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning\PlanningAttributionWriter;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicDetailQuery;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicListQuery;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicPlanningRef;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Contract: SEO Audit Notes suggestions reconnect to Topic Core (no legacy cluster).
 */
final class AuditNoteTopicCoreReconnectContractTest extends TestCase
{
    public function test_suggestion_query_sources_topic_core_not_legacy_cluster(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(AuditNoteClusterSuggestionQuery::class))->getFileName(),
        );

        self::assertStringContainsString('KeywordLandscapeGateway', $src);
        self::assertStringContainsString('TopicPlanningRef', $src);
        self::assertStringContainsString('TopicHistoryReadModel', $src);
        self::assertStringContainsString('seo_topic_keyword_dna', $src);
        self::assertStringContainsString('SeoTopicKeywordDna', $src);
        self::assertStringContainsString('planned_history_count', $src);

        self::assertStringNotContainsString('KeywordLandscapeReadModel', $src);
        self::assertStringNotContainsString('TopicListQuery', $src);
        self::assertStringNotContainsString('TopicDetailQuery', $src);
        self::assertStringNotContainsString('topical_share', $src);
        self::assertStringNotContainsString('seo_keyword_dna', $src);
        self::assertStringNotContainsString('cluster_key', $src);
        self::assertStringNotContainsString('seo_topic_cluster_meta', $src);
        self::assertStringNotContainsString('KeywordClusterQuery', $src);
        self::assertStringNotContainsString('SiteMcpClusterTopicalProfileBuilder', $src);
        self::assertStringNotContainsString('KeywordDnaService', $src);
        self::assertStringNotContainsString('SeoKeywordDna', $src);
    }

    public function test_public_api_and_compat_fields_preserved(): void
    {
        $ref = new ReflectionClass(AuditNoteClusterSuggestionQuery::class);
        self::assertTrue($ref->hasMethod('paginate'));
        self::assertTrue($ref->hasMethod('findSuggestion'));
        self::assertTrue($ref->hasMethod('findExactNormalizedNameMatches'));
        self::assertTrue($ref->hasMethod('dnaPhrasesForCluster'));
        self::assertSame(25, AuditNoteClusterSuggestionQuery::PER_PAGE);
        self::assertSame(30, AuditNoteClusterSuggestionQuery::DNA_LIMIT);

        $src = (string) file_get_contents((string) $ref->getFileName());
        self::assertStringContainsString("'cluster_ref'", $src);
        self::assertStringContainsString("'mcp_share'", $src);
        self::assertStringContainsString("'has_focus_article'", $src);
        self::assertStringContainsString('mcp_low', $src);
        self::assertStringContainsString('has_focus', $src);
        self::assertStringContainsString('no_focus', $src);
        self::assertStringContainsString('TopicPlanningRef::encode', $src);
    }

    public function test_exact_matcher_still_delegates_to_suggestion_query(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(AuditNotePlannerExactTopicMatcher::class))->getFileName(),
        );
        self::assertStringContainsString('AuditNoteClusterSuggestionQuery', $src);
        self::assertStringContainsString('findExactNormalizedNameMatches', $src);
    }

    public function test_planning_attribution_resolves_modern_topic_ref_name(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(PlanningAttributionWriter::class))->getFileName(),
        );
        self::assertStringContainsString('TopicPlanningRef::decode', $src);
        self::assertStringContainsString('SeoTopic::query()', $src);
        self::assertStringContainsString('site_id', $src);
        self::assertStringNotContainsString('seo_topic_cluster_meta', $src);
        self::assertStringNotContainsString('cluster_key', $src);
    }

    public function test_manual_seed_prefix_unchanged(): void
    {
        self::assertSame('manual:', AuditNoteDnaNormalizer::MANUAL_REF_PREFIX);
        self::assertFalse(TopicPlanningRef::isTopicRef(AuditNoteDnaNormalizer::MANUAL_REF_PREFIX.'balo'));
        self::assertNull(TopicPlanningRef::decode(AuditNoteDnaNormalizer::MANUAL_REF_PREFIX.'balo'));
    }

    public function test_topic_planning_ref_identity_format(): void
    {
        self::assertSame('topic:12', TopicPlanningRef::encode(12));
        self::assertSame(12, TopicPlanningRef::decode('topic:12'));
        self::assertTrue(class_exists(TopicListQuery::class));
        self::assertTrue(class_exists(TopicDetailQuery::class));
    }
}
