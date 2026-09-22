<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithAuditNotes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteClusterSuggestionQuery;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicDetailQuery;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicListQuery;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicPlanningRef;
use Omnichannel\Addons\Seo\Services\KeywordLandscape\KeywordLandscapeGateway;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * PR2: Suggest Notes consumes Keyword Landscape via gateway (not ReadModel).
 */
final class AuditNoteKeywordLandscapeBoundaryContractTest extends TestCase
{
    public function test_suggestion_query_uses_gateway_not_read_model(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(AuditNoteClusterSuggestionQuery::class))->getFileName(),
        );

        self::assertStringContainsString('KeywordLandscapeGateway', $src);
        self::assertStringContainsString('landscape->forSite', $src);
        self::assertStringContainsString('landscape->findTopic', $src);
        self::assertStringContainsString('TopicHistoryReadModel', $src);
        self::assertStringContainsString('planned_history_count', $src);
        self::assertStringContainsString('SEO Audit → KeywordLandscapeGateway', $src);
        self::assertStringNotContainsString('use Omnichannel\\Addons\\SearchIntelligence\\Services\\Topic\\KeywordLandscapeReadModel', $src);
        self::assertStringNotContainsString('KeywordLandscapeReadModel', $src);
        self::assertStringNotContainsString('TopicListQuery', $src);
        self::assertStringNotContainsString('TopicDetailQuery', $src);
        self::assertStringNotContainsString('TopicTopicalShareCalculator', $src);
        self::assertStringNotContainsString('TopicLinkedArticleCounter', $src);
        self::assertStringNotContainsString('topicalShareForTopic', $src);
        self::assertStringNotContainsString('loadTopicDna', $src);
        self::assertStringNotContainsString('cluster_key', $src);
        self::assertStringNotContainsString('EMPTY_LANDSCAPE', $src);
        self::assertStringNotContainsString('Http::', $src);
        self::assertStringNotContainsString('/api/v1/agent/mcp', $src);
    }

    public function test_livewire_concern_still_delegates_to_suggestion_query(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(InteractsWithAuditNotes::class))->getFileName(),
        );

        self::assertStringContainsString('AuditNoteClusterSuggestionQuery::class', $src);
        self::assertStringContainsString('->paginate(', $src);
        self::assertStringContainsString('->findSuggestion(', $src);
        self::assertStringNotContainsString('KeywordLandscapeReadModel', $src);
        self::assertStringNotContainsString('KeywordLandscapeGateway', $src);
        self::assertStringNotContainsString('TopicListQuery', $src);
    }

    public function test_public_transport_fields_and_filters_preserved(): void
    {
        $ref = new ReflectionClass(AuditNoteClusterSuggestionQuery::class);
        self::assertTrue($ref->hasMethod('paginate'));
        self::assertTrue($ref->hasMethod('findSuggestion'));
        self::assertTrue($ref->hasMethod('findExactNormalizedNameMatches'));
        self::assertTrue($ref->hasMethod('dnaPhrasesForCluster'));
        self::assertSame(25, AuditNoteClusterSuggestionQuery::PER_PAGE);
        self::assertSame(KeywordLandscapeGateway::DNA_LIMIT, AuditNoteClusterSuggestionQuery::DNA_LIMIT);

        $src = (string) file_get_contents((string) $ref->getFileName());
        self::assertStringContainsString("'cluster_ref'", $src);
        self::assertStringContainsString("'mcp_share'", $src);
        self::assertStringContainsString("'has_focus_article'", $src);
        self::assertStringContainsString('mcp_low', $src);
        self::assertStringContainsString('has_focus', $src);
        self::assertStringContainsString('no_focus', $src);
        self::assertStringContainsString('TopicPlanningRef::encode', $src);
        self::assertTrue(class_exists(TopicPlanningRef::class));
        self::assertTrue(class_exists(TopicListQuery::class));
        self::assertTrue(class_exists(TopicDetailQuery::class));
    }

    public function test_no_hidden_legacy_fallback_in_suggestion_query(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(AuditNoteClusterSuggestionQuery::class))->getFileName(),
        );
        self::assertStringNotContainsString('fallback', mb_strtolower($src));
        self::assertStringNotContainsString('try {', $src);
        self::assertStringNotContainsString('catch (', $src);
    }
}
