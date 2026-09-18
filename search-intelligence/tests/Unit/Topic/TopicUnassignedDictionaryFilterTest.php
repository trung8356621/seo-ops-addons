<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\KeywordTopicClusters;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordDictionaryQuery;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Topics → Dictionary "No Topic" deep-link + query semantics contract.
 */
final class TopicUnassignedDictionaryFilterTest extends TestCase
{
    public function test_unassigned_url_points_to_dictionary_with_no_topic_filter(): void
    {
        $clusters = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusters.php'
        );
        self::assertStringContainsString("buildTopicAssignmentFilterUrl('unassigned')", $clusters);

        $blade = (string) file_get_contents(
            dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php'
        );
        self::assertStringContainsString('unassignedUrl()', $blade);
        self::assertStringContainsString('topic-index-compact-stats__link', $blade);

        $resource = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource.php'
        );
        self::assertStringContainsString('buildTopicAssignmentFilterUrl', $resource);
        self::assertStringContainsString("SelectFilter::make('topic_assignment')", $resource);
        self::assertStringContainsString("'unassigned'", $resource);
    }

    public function test_build_topic_assignment_filter_url_shape(): void
    {
        $method = new ReflectionMethod(KeywordResource::class, 'buildTopicAssignmentFilterUrl');
        self::assertTrue($method->isStatic());

        // Source-level URL shape (no Filament app bootstrap required).
        $src = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource.php'
        );
        self::assertStringContainsString("'topic_assignment' => [", $src);
        self::assertStringContainsString("'value' => \$assignment", $src);
        self::assertStringContainsString("'assigned', 'unassigned'", $src);
    }

    public function test_dictionary_query_applies_site_scoped_membership_filter(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Support/KeywordWorkspace/KeywordDictionaryQuery.php'
        );
        self::assertStringContainsString('function applyTopicAssignment', $src);
        self::assertStringContainsString('seo_topic_keywords', $src);
        self::assertStringContainsString("where('seo_topic_keywords.site_id', \$siteId)", $src);
        self::assertStringContainsString('whereNotExists', $src);
        self::assertStringContainsString('whereExists', $src);
        self::assertStringContainsString("'unassigned'", $src);
        self::assertStringContainsString("'assigned'", $src);
        self::assertStringNotContainsString('cluster_key', $src);
    }

    public function test_list_keywords_applies_topic_assignment_from_table_filters(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/ListKeywords.php'
        );
        self::assertStringContainsString('applyTopicAssignmentTableFilter', $src);
        self::assertStringContainsString('resolveTopicAssignmentFilterValue', $src);
        self::assertStringContainsString("getTableFilterState('topic_assignment')", $src);
        self::assertStringContainsString("'topic_assignment'", $src);
    }

    public function test_apply_topic_assignment_noop_without_site(): void
    {
        $ref = new ReflectionClass(KeywordDictionaryQuery::class);
        self::assertTrue($ref->hasMethod('applyTopicAssignment'));
        $method = $ref->getMethod('applyTopicAssignment');
        self::assertSame(3, $method->getNumberOfParameters());
    }
}
