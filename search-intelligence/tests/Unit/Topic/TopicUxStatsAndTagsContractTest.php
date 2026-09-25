<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicListQuery;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicManualOwnership;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicUserTagService;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordTopicAssignmentStats;
use PHPUnit\Framework\TestCase;

/**
 * Topics tab count, shared unassigned/No Topic stats, user tags contracts.
 */
final class TopicUxStatsAndTagsContractTest extends TestCase
{
    public function test_nav_exposes_topics_tab_count(): void
    {
        $nav = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/Concerns/HasKeywordWorkspaceNavigation.php'
        );
        self::assertStringContainsString("'topics' =>", $nav);
        self::assertStringContainsString("'count' => \$counts['topics']", $nav);
        self::assertStringContainsString('topic_count', $nav);
    }

    public function test_assignment_stats_ssot_uses_dictionary_inventory_and_membership(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Support/KeywordWorkspace/KeywordTopicAssignmentStats.php'
        );
        self::assertStringContainsString('KeywordUiInventoryQuery', $src);
        self::assertStringContainsString("'topic_assignment' => 'unassigned'", $src);
        self::assertStringContainsString("'topic_assignment' => 'assigned'", $src);
        self::assertStringContainsString('seo_eligible_clustering', $src);
        self::assertTrue(class_exists(KeywordTopicAssignmentStats::class));
    }

    public function test_topic_summary_delegates_to_assignment_stats(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Services/Topic/TopicListQuery.php'
        );
        self::assertStringContainsString('KeywordTopicAssignmentStats', $src);
        self::assertStringContainsString('languageVariants', $src);
        self::assertStringContainsString("'seo_eligible_keywords' => \$stats['inventory_total']", $src);
        self::assertStringContainsString("'unassigned' => \$stats['unassigned']", $src);
        self::assertStringContainsString('paginate(int $siteId, array $filters = [], ?array $languageVariants = null)', $src);
        self::assertTrue(class_exists(TopicListQuery::class));
    }

    public function test_dictionary_no_topic_mode_uses_filtered_total_as_primary(): void
    {
        $list = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/ListKeywords.php'
        );
        $blade = (string) file_get_contents(
            dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/partials/keyword-dictionary-stats.blade.php'
        );
        self::assertStringContainsString("'mode' => 'no_topic'", $list);
        self::assertStringContainsString("'no_topic' => \$total", $list);
        self::assertStringContainsString('stat_no_topic', $blade);
        self::assertStringContainsString("\$mode === 'no_topic'", $blade);
    }

    public function test_unassigned_deep_link_still_uses_topic_assignment_filter(): void
    {
        $clusters = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusters.php'
        );
        $dict = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Support/KeywordWorkspace/KeywordDictionaryQuery.php'
        );
        self::assertStringContainsString("buildTopicAssignmentFilterUrl('unassigned')", $clusters);
        self::assertStringContainsString('seo_topic_keywords', $dict);
        self::assertStringContainsString('whereNotExists', $dict);
    }

    public function test_topic_user_tags_are_site_scoped_vocabulary_not_keyword_tags(): void
    {
        $service = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Services/Topic/TopicUserTagService.php'
        );
        $migration = (string) file_get_contents(
            dirname(__DIR__, 3).'/database/migrations/2026_09_18_160000_rebuild_seo_topic_tags_vocabulary.php'
        );
        $clusters = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusters.php'
        );
        $recluster = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Services/Topic/TopicReclusterService.php'
        );
        $ownership = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Services/Topic/TopicManualOwnership.php'
        );

        self::assertStringContainsString('seo_topic_tags', $migration);
        self::assertStringContainsString('seo_topic_tag_assignments', $migration);
        self::assertStringContainsString('site_id', $service);
        self::assertStringNotContainsString('keyword_tags', $service);
        self::assertStringNotContainsString('TagPersistenceService', $service);
        self::assertStringContainsString('do NOT promote auto→manual', $service);
        self::assertStringContainsString('attachTopicTag', $clusters);
        self::assertStringContainsString('detachTopicTag', $clusters);
        self::assertStringContainsString('topicTags', $clusters);
        self::assertStringContainsString('deleteForTopics', $recluster);
        self::assertStringNotContainsString('TopicManualOwnership', $service);
        self::assertStringNotContainsString('promoteIfAuto', $service);
        self::assertTrue(class_exists(TopicUserTagService::class));
        self::assertTrue(class_exists(TopicManualOwnership::class));
        self::assertStringContainsString('lock semantics remain separate', $ownership);
    }

    public function test_recluster_does_not_rewrite_topic_tags_except_on_dissolve_cleanup(): void
    {
        $recluster = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Services/Topic/TopicReclusterService.php'
        );
        self::assertStringContainsString('deleteForTopics($staleIds)', $recluster);
        self::assertStringNotContainsString('attach(', $recluster);
        self::assertStringNotContainsString('attachByName', $recluster);
        self::assertStringNotContainsString('TopicUserTagService::class)->attach', $recluster);
    }
}
