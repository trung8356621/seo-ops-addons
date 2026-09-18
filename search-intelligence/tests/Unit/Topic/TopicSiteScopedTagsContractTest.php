<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicTag;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicTagAssignment;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicBuiltinShortcutStats;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicListQuery;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicUserTagService;
use PHPUnit\Framework\TestCase;

/**
 * Site-scoped immutable Topic tags — schema / isolation / UX contracts.
 */
final class TopicSiteScopedTagsContractTest extends TestCase
{
    public function test_vocabulary_migration_has_site_scoped_immutable_shape(): void
    {
        $migration = (string) file_get_contents(
            dirname(__DIR__, 3).'/database/migrations/2026_09_18_160000_rebuild_seo_topic_tags_vocabulary.php'
        );

        self::assertStringContainsString("create('seo_topic_tags'", $migration);
        self::assertStringContainsString("create('seo_topic_tag_assignments'", $migration);
        self::assertStringContainsString('site_id', $migration);
        self::assertStringContainsString('slug', $migration);
        self::assertStringContainsString('seo_topic_tags_site_slug_uq', $migration);
        self::assertStringContainsString('seo_topic_tags_site_name_idx', $migration);
        self::assertStringContainsString('seo_topic_tag_assignments_pk', $migration);
        self::assertStringNotContainsString('updated_at', $migration);
        self::assertStringNotContainsString('is_system', $migration);
        self::assertStringNotContainsString('taggable', $migration);
        self::assertStringNotContainsString('keyword_id', $migration);
    }

    public function test_models_disable_updated_at(): void
    {
        $tagSrc = (string) file_get_contents(dirname(__DIR__, 3).'/src/Models/SeoTopicTag.php');
        $assignSrc = (string) file_get_contents(dirname(__DIR__, 3).'/src/Models/SeoTopicTagAssignment.php');

        self::assertStringContainsString('UPDATED_AT = null', $tagSrc);
        self::assertStringContainsString('UPDATED_AT = null', $assignSrc);
        self::assertStringNotContainsString("'updated_at'", $tagSrc);
        self::assertStringNotContainsString("'updated_at'", $assignSrc);
        self::assertTrue(class_exists(SeoTopicTag::class));
        self::assertTrue(class_exists(SeoTopicTagAssignment::class));
    }

    public function test_service_enforces_site_isolation_and_immutability(): void
    {
        $service = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Services/Topic/TopicUserTagService.php'
        );

        self::assertStringContainsString('site_mismatch', $service);
        self::assertStringContainsString('findOrCreate', $service);
        self::assertStringContainsString('deleteTag', $service);
        self::assertStringContainsString('do NOT promote auto→manual', $service);
        self::assertStringNotContainsString('function rename', $service);
        self::assertStringNotContainsString('function updateTag', $service);
        self::assertStringNotContainsString('TagPersistenceService', $service);
        self::assertStringNotContainsString('keyword_tags', $service);
        self::assertStringNotContainsString('promoteIfAuto', $service);
        self::assertTrue(class_exists(TopicUserTagService::class));
    }

    public function test_list_query_uses_and_semantics_and_rejects_cross_site_tags(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Services/Topic/TopicListQuery.php'
        );

        self::assertStringContainsString('Custom tags: AND semantics', $src);
        self::assertStringContainsString('seo_topic_tag_assignments', $src);
        self::assertStringContainsString('Forged/cross-site tag id', $src);
        self::assertStringContainsString("'intent'", $src);
        self::assertStringContainsString("'coverage'", $src);
        self::assertStringContainsString("'source'", $src);
        self::assertTrue(class_exists(TopicListQuery::class));
    }

    public function test_tags_tab_and_nav_are_wired(): void
    {
        $nav = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/Concerns/HasKeywordWorkspaceNavigation.php'
        );
        $resource = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource.php'
        );
        $page = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicTags.php'
        );
        $blade = (string) file_get_contents(
            dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-tags.blade.php'
        );
        $shortcuts = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Services/Topic/TopicBuiltinShortcutStats.php'
        );

        self::assertStringContainsString("'key' => 'tags'", $nav);
        self::assertStringContainsString("'count' => \$counts['tags']", $nav);
        self::assertStringContainsString('countForSite', $nav);
        self::assertStringContainsString("KeywordTopicTags::route('/topic-tags')", $resource);
        self::assertStringContainsString("getUrl('topic-tags')", $nav);
        self::assertStringContainsString('TopicBuiltinShortcutStats', $page);
        self::assertStringContainsString('deleteCustomTag', $page);
        self::assertStringContainsString('topicsUrlForCustomTag', $page);
        self::assertStringContainsString('topicsUrlForBuiltin', $page);
        self::assertStringContainsString('topic_tags_builtin_heading', $blade);
        self::assertStringContainsString('deleteCustomTag', $blade);
        self::assertStringContainsString('Does NOT materialize rows into seo_topic_tags', $shortcuts);
        self::assertStringContainsString("'intent' => 'commercial'", $shortcuts);
        self::assertStringContainsString("'coverage' => 'strong'", $shortcuts);
        self::assertStringContainsString("TopicSource::AUTO", $shortcuts);
        self::assertTrue(class_exists(TopicBuiltinShortcutStats::class));
    }

    public function test_topics_page_has_searchable_multi_tag_filter_and_add_tag(): void
    {
        $clusters = (string) file_get_contents(
            dirname(__DIR__, 3).'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusters.php'
        );
        $blade = (string) file_get_contents(
            dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/topic-cluster-index.blade.php'
        );

        self::assertStringContainsString("Url(as: 'topic_tags')", $clusters);
        self::assertStringContainsString("Url(as: 'intent')", $clusters);
        self::assertStringContainsString("Url(as: 'coverage')", $clusters);
        self::assertStringContainsString("Url(as: 'source')", $clusters);
        self::assertStringContainsString('searchTopicTags', $clusters);
        self::assertStringContainsString('toggleTopicTagFilter', $clusters);
        self::assertStringContainsString('pruneInvalidTopicTagFilter', $clusters);
        self::assertStringContainsString('attachTopicTagByName', $clusters);
        self::assertStringNotContainsString('SearchFoundation\\Models\\Tag', $clusters);
        self::assertStringContainsString('searchTopicTags', $blade);
        self::assertStringContainsString('toggleTopicTagFilter', $blade);
        self::assertStringContainsString('openTagPicker', $blade);
        self::assertStringContainsString('topic_tag_filter_and_hint', $blade);
        self::assertStringContainsString('topic_tag_create_prefix', $blade);
        self::assertStringNotContainsString('wire:model.live="topicTagFilter"', $blade);
    }

    public function test_legacy_keyword_tags_vocabulary_is_retired(): void
    {
        self::assertFileDoesNotExist(
            dirname(__DIR__, 4).'/search-foundation/src/Models/Tag.php'
        );
        self::assertFileDoesNotExist(
            dirname(__DIR__, 4).'/search-foundation/src/Services/TagPersistenceService.php'
        );
        self::assertFileDoesNotExist(
            dirname(__DIR__, 4).'/content/src/Filament/Resources/TagResource.php'
        );
        self::assertFileExists(
            dirname(__DIR__, 3).'/database/migrations/2026_09_18_170000_drop_legacy_keyword_tags_table.php'
        );

        $drop = (string) file_get_contents(
            dirname(__DIR__, 3).'/database/migrations/2026_09_18_170000_drop_legacy_keyword_tags_table.php'
        );
        self::assertStringContainsString("drop('keyword_tags')", $drop);
        self::assertStringContainsString('omi_seo_ai', $drop);
    }
}
