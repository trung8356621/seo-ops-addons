<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicClusterEngine;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicMembershipMatcher;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicSeedIdentityResolver;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Support\TopicPhraseResolver;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordCanonicalizer;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordNormalizer;
use Omnichannel\Addons\SearchIntelligence\Enums\Topic\TopicKeywordSource;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for restored Topic UI wired to site-scoped Topic Core.
 */
final class TopicUiRestoreContractTest extends TestCase
{
    public function test_list_and_detail_pages_exist_with_topic_id_route(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertFileExists($root.'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusters.php');
        self::assertFileExists($root.'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusterDetail.php');
        self::assertFileExists($root.'/src/Services/Topic/TopicListQuery.php');
        self::assertFileExists($root.'/src/Services/Topic/TopicDetailQuery.php');

        $resource = (string) file_get_contents($root.'/src/Filament/Resources/KeywordResource.php');
        self::assertStringContainsString("KeywordTopicClusters::route('/clusters')", $resource);
        self::assertStringContainsString("KeywordTopicClusterDetail::route('/clusters/{topic}')", $resource);
        self::assertStringNotContainsString('{clusterKey}', $resource);
    }

    public function test_detail_page_enforces_site_scoped_lookup(): void
    {
        $path = dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusterDetail.php';
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('TopicDetailQuery', $src);
        self::assertStringContainsString('abort_unless($this->getDetail() !== null, 404)', $src);
        self::assertStringContainsString('resolveKeywordWorkspaceSiteId', $src);
        self::assertStringNotContainsString('cluster_key', $src);
        self::assertStringNotContainsString('KeywordClusterQuery', $src);
    }

    public function test_list_query_is_site_scoped_and_uses_topic_id(): void
    {
        $path = dirname(__DIR__, 3).'/src/Services/Topic/TopicListQuery.php';
        $src = (string) file_get_contents($path);
        self::assertGreaterThanOrEqual(4, substr_count($src, "where('site_id', \$siteId)"));
        self::assertStringContainsString("'topic_id'", $src);
        self::assertStringNotContainsString('cluster_key', $src);
        self::assertStringContainsString('TopicLinkedArticleCounter', $src);
    }

    public function test_detail_query_membership_comes_from_seo_topic_keywords(): void
    {
        $path = dirname(__DIR__, 3).'/src/Services/Topic/TopicDetailQuery.php';
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('SeoTopicKeyword::query()', $src);
        self::assertStringContainsString('SeoTopicKeywordDna::query()', $src);
        self::assertStringContainsString("where('site_id', \$siteId)", $src);
        self::assertStringContainsString("where('topic_id', \$topicId)", $src);
        self::assertStringNotContainsString('cluster_key', $src);
        self::assertStringNotContainsString('KeywordClusterService', $src);
    }

    public function test_rename_wires_to_topic_rename_service_only(): void
    {
        $list = (string) file_get_contents(dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusters.php');
        $detail = (string) file_get_contents(dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusterDetail.php');
        self::assertStringContainsString('TopicRenameService', $list);
        self::assertStringContainsString('TopicRenameService', $detail);
        self::assertStringContainsString('->rename($siteId, $topicId', $list);
        self::assertStringContainsString('->rename($siteId, $this->topic', $detail);
        self::assertStringNotContainsString('UpdateClusterCanonicalService', $list.$detail);
    }

    public function test_lock_semantics_distinguish_topic_and_membership(): void
    {
        $listQ = (string) file_get_contents(dirname(__DIR__, 3).'/src/Services/Topic/TopicListQuery.php');
        $blade = (string) file_get_contents(dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php');
        self::assertStringContainsString('has_membership_locks', $listQ);
        self::assertStringContainsString('topic_tag_topic_locked', $blade);
        self::assertStringContainsString('topic_tag_membership_locked', $blade);
        self::assertStringContainsString('membership_locked', $listQ);
        // Membership lock must not imply full topic lock flag.
        self::assertStringContainsString("'is_locked' => \$isTopicLocked", $listQ);
    }

    public function test_article_counter_never_aggregates_by_keyword_ids_alone(): void
    {
        $path = dirname(__DIR__, 3).'/src/Services/Topic/TopicLinkedArticleCounter.php';
        $src = (string) file_get_contents($path);
        self::assertStringContainsString("where('site_id', \$siteId)", $src);
        self::assertStringContainsString('sourceArticle', $src);
        self::assertStringContainsString('NEVER aggregate by keyword_id alone', $src);
    }

    public function test_recluster_wires_to_topic_core_job_not_legacy(): void
    {
        $concern = (string) file_get_contents(dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/Concerns/ReclustersSiteTopics.php');
        $job = (string) file_get_contents(dirname(__DIR__, 3).'/src/Jobs/ReclusterSiteTopicsJob.php');
        self::assertStringContainsString('ReclusterSiteTopicsJob', $concern);
        self::assertStringContainsString('TopicReclusterService', $concern);
        self::assertStringNotContainsString('ReclusterTopicClustersJob', $concern);
        self::assertStringNotContainsString('ReclusterTopicClustersService', $concern);
        self::assertStringContainsString('TopicReclusterUiState', $job);
        self::assertStringContainsString('TopicReclusterAlgorithm', $job);
        self::assertStringNotContainsString('cluster_key', $concern.$job);
    }

    public function test_unmatched_seo_keyword_still_does_not_become_topic(): void
    {
        $phrases = new TopicPhraseResolver(new KeywordNormalizer, new KeywordCanonicalizer);
        $engine = new TopicClusterEngine(
            new TopicMembershipMatcher($phrases),
            new KeywordNormalizer,
            $phrases,
        );
        $topics = $engine->cluster(
            [[
                'keyword_id' => 1,
                'phrase' => 'balo quà tặng',
                'source' => TopicKeywordSource::LINK_LIST,
                'is_seed' => true,
                'confidence' => 1.0,
            ]],
            [
                ['keyword_id' => 1, 'phrase' => 'balo quà tặng', 'is_seo_keyword' => true],
                ['keyword_id' => 9, 'phrase' => 'cách giặt áo', 'is_seo_keyword' => true],
            ],
        );
        self::assertCount(1, $topics);
        foreach ($topics as $topic) {
            foreach ($topic['members'] as $member) {
                self::assertNotSame(9, $member['keyword_id']);
            }
        }
    }

    public function test_seed_identity_preserves_topic_id_across_recluster(): void
    {
        $applied = (new TopicSeedIdentityResolver)->apply([[
            'name' => 'Renamed topic',
            'topic_id' => null,
            'is_locked' => false,
            'members' => [[
                'keyword_id' => 50,
                'phrase' => 'seed',
                'source' => TopicKeywordSource::LINK_LIST,
                'is_seed' => true,
                'confidence' => 1.0,
                'is_locked' => false,
            ]],
        ]], [50 => 12]);
        self::assertSame(12, $applied[0]['topic_id']);
    }

    public function test_restored_runtime_has_no_cluster_key_dependency(): void
    {
        $files = [
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusters.php',
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusterDetail.php',
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/Concerns/ReclustersSiteTopics.php',
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/Concerns/DissolvesTopics.php',
            dirname(__DIR__, 3).'/src/Services/Topic/TopicListQuery.php',
            dirname(__DIR__, 3).'/src/Services/Topic/TopicDetailQuery.php',
            dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php',
            dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-detail.blade.php',
        ];
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression('/\bcluster_key\b/', $src, basename($file));
            self::assertStringNotContainsString('KeywordClusterService', $src, basename($file));
            self::assertStringNotContainsString('seo_topic_cluster_meta', $src, basename($file));
            self::assertStringNotContainsString('seo_topic_cluster_aliases', $src, basename($file));
            self::assertStringNotContainsString('TopicalMap', $src, basename($file));
            self::assertStringNotContainsString('KeywordClusterQuery', $src, basename($file));
            self::assertStringNotContainsString('CreateManualTopicClusterService', $src, basename($file));
        }
    }

    public function test_nav_exposes_topics_tab(): void
    {
        $nav = (string) file_get_contents(dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/Concerns/HasKeywordWorkspaceNavigation.php');
        self::assertStringContainsString("'key' => 'clusters'", $nav);
        self::assertStringContainsString("getUrl('clusters')", $nav);
        $resource = (string) file_get_contents(dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource.php');
        self::assertStringContainsString("getUrl('clusters')", $resource);
        self::assertStringContainsString('isKeywordsClustersNav', $resource);
    }

    public function test_index_blade_keeps_golden_shell_dom(): void
    {
        $blade = (string) file_get_contents(dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php');
        foreach ([
            'keyword-workspace-shell',
            'topic-index-section-heading',
            'topic-index-compact-stats',
            'topic-index-context',
            'topic-index-context-card',
            'topic-index-toolbar',
            'topic-index-toolbar__primary',
            'topic-index-filters',
            'cluster-index-list',
            'cluster-index-row',
            'cluster-index-row__main',
            'cluster-index-row__title-wrap',
            'cluster-index-row__meta',
            'cluster-tag-row',
            'topic-row-actions-menu',
            'saveTopicNameFromIndex',
            '@dblclick',
        ] as $needle) {
            self::assertStringContainsString($needle, $blade, $needle);
        }
        self::assertStringNotContainsString('Open  Dissolve topic', $blade);
    }

    public function test_detail_blade_keeps_golden_shell_dom(): void
    {
        $blade = (string) file_get_contents(dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-detail.blade.php');
        foreach ([
            'cluster-detail-header',
            'cluster-detail-header__title',
            'cluster-detail-header__stats',
            'cluster-detail-dna-panel',
            'topic-keyword-member-table',
            'data-keyword-detail-row',
            'keyword-item-table-cell',
            'fi-ta-actions-cell',
            'keyword-item-actions',
            'CONTEXT_DICTIONARY',
            'keyword-detail-drawer',
            'saveTopicName',
            '@dblclick',
            'dissolveCurrentTopic',
        ] as $needle) {
            self::assertStringContainsString($needle, $blade, $needle);
        }
        self::assertStringNotContainsString('{clusterKey}', $blade);
        self::assertStringNotContainsString('KeywordClusterDetailBuilder', $blade);
    }
}
