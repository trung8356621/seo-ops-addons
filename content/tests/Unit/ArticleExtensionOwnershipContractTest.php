<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contract: article extension ownership cutover (Task 4).
 */
final class ArticleExtensionOwnershipContractTest extends TestCase
{
    private function projectRoot(): string
    {
        return \Tests\Support\ProjectRoot::path();
    }

    public function test_ownership_json_lists_moved_columns(): void
    {
        $path = $this->projectRoot().'/docs/architecture/ARTICLE_COLUMN_OWNERSHIP.json';
        self::assertFileExists($path);
        $json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $byColumn = [];
        foreach ($json['columns'] as $row) {
            $byColumn[$row['column']] = $row;
        }

        self::assertSame('SEO', $byColumn['seo_score']['owner']);
        self::assertSame('move', $byColumn['seo_score']['action']);
        self::assertSame('WORDPRESS', $byColumn['wp_post_id']['owner']);
        self::assertSame('MEDIA', $byColumn['featured_media_id']['owner']);
        self::assertSame('PUBLISHING', $byColumn['published_at']['owner']);
        self::assertSame('CONTENT PROJECTS', $byColumn['content_archived_at']['owner']);
        self::assertSame('CONTENT', $byColumn['body']['owner']);
        self::assertSame('keep', $byColumn['body']['action']);
    }

    public function test_extension_migrations_exist(): void
    {
        $root = $this->projectRoot();
        self::assertFileExists($root.'/addons/seo/database/migrations/2026_08_10_120000_create_seo_article_profiles_table.php');
        self::assertFileExists($root.'/addons/wordpress/database/migrations/2026_08_10_120100_create_wordpress_article_links_table.php');
        self::assertFileExists($root.'/addons/media/database/migrations/2026_08_10_120200_create_article_media_states_table.php');
        self::assertFileExists($root.'/addons/publishing/database/migrations/2026_08_10_120300_create_publishing_article_states_table.php');
        self::assertFileExists($root.'/addons/content-projects/database/migrations/2026_08_10_120400_backfill_content_archive_items_from_articles.php');
    }

    public function test_seo_analyzer_dual_writes_profile(): void
    {
        $source = (string) file_get_contents(
            $this->projectRoot().'/addons/seo/src/Services/SeoAnalyzerService.php',
        );
        self::assertStringContainsString('SeoArticleProfileWriter', $source);
    }

    public function test_featured_projection_dual_writes_media_state(): void
    {
        $source = (string) file_get_contents(
            $this->projectRoot().'/addons/media/src/Services/ArticleFeaturedImageProjection.php',
        );
        self::assertStringContainsString('ArticleMediaStateWriter', $source);
        self::assertStringContainsString('upsertFeatured', $source);
    }

    public function test_wp_lease_dual_writes_article_link(): void
    {
        $source = (string) file_get_contents(
            $this->projectRoot().'/addons/wordpress/src/Services/ArticleWpSyncLeaseService.php',
        );
        self::assertStringContainsString('WordpressArticleLinkWriter', $source);
    }

    public function test_media_domain_flush_owns_featured_key(): void
    {
        $source = (string) file_get_contents(
            $this->projectRoot().'/addons/media/resources/js/editor/domains/media/state.js',
        );
        self::assertStringContainsString('featured_image', $source);
        self::assertStringContainsString('media_snapshot', $source);
        self::assertStringContainsString('getMediaSnapshot', $source);
    }

    public function test_coordinated_save_omits_featured_when_untouched(): void
    {
        $source = (string) file_get_contents(
            $this->projectRoot().'/addons/content/resources/js/utils/articleEditorApi.js',
        );
        self::assertStringContainsString('buildCoordinatedArticleSavePayload', $source);
        self::assertStringContainsString('flushAllSaveOwners', $source);
        self::assertStringContainsString("flushMedia: false", $source);
        self::assertStringContainsString('hasOwnProperty.call(mediaSnapshot, \'featured\')', $source);
    }
}
