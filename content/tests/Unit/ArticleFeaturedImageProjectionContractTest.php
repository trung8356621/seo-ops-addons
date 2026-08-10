<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use PHPUnit\Framework\TestCase;

/**
 * Canonical featured projection for Article List Ã¢â‚¬â€ DB columns, no WP HTTP, no wp_post_images.
 */
final class ArticleFeaturedImageProjectionContractTest extends TestCase
{
    public function test_migration_adds_projection_columns(): void
    {
        $path = \Omnichannel\Addons\Seo\Support\SeoMigrationPath::find('2026_08_05_110000_add_featured_image_projection_to_articles_table.php');
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('featured_thumb_url', $source);
        self::assertStringContainsString('featured_media_id', $source);
        self::assertStringContainsString('featured_image_status', $source);
        self::assertStringContainsString('featured_image_source', $source);
        self::assertStringContainsString("protected \$connection = 'omi_seo_ai'", $source);
    }

    public function test_list_select_includes_projection_not_body(): void
    {
        $resource = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource.php',
        );

        self::assertStringContainsString('featured_thumb_url', $resource);
        self::assertStringContainsString('featured_image_status', $resource);
        self::assertStringContainsString('hasFeaturedProjectionColumns', $resource);
        self::assertStringNotContainsString("'articles.body'", $resource);
        self::assertStringNotContainsString("'articles.blocks'", $resource);
        self::assertStringNotContainsString("'articles.editor_document'", $resource);
    }

    public function test_list_column_uses_projection_view_not_image_column_placeholder_only(): void
    {
        $resource = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource.php',
        );

        self::assertStringContainsString('ViewColumn::make(\'featured_thumb\')', $resource);
        self::assertStringContainsString('article-list-thumbnail', $resource);
        self::assertStringContainsString('ArticleFeaturedImageProjection', $resource);
        self::assertStringContainsString('forList($record)', $resource);
    }

    public function test_list_for_list_reads_attributes_only_no_wp_post_images(): void
    {
        $resolver = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Support/ArticleFeaturedImageResolver.php',
        );

        self::assertStringContainsString('function forList', $resolver);
        self::assertStringContainsString('function rebuildFromStoredSources', $resolver);
        self::assertStringContainsString('Never HTTP, never body parse', $resolver);
        // Forbid reading content-image gallery meta as featured (call/key only Ã¢â‚¬â€ not comments).
        self::assertDoesNotMatchRegularExpression(
            "/['\"]wp_post_images['\"]/",
            $resolver,
        );
        self::assertStringNotContainsString('Http::', $resolver);
        self::assertStringNotContainsString('fetchFromWordPress', $resolver);
        self::assertStringNotContainsString('resolveEditorHtml', $resolver);
    }

    public function test_write_through_hooks_on_local_featured_mutations(): void
    {
        $local = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/src/Services/ArticleMediaLocalService.php',
        );

        self::assertStringContainsString('ArticleFeaturedImageProjection', $local);
        self::assertStringContainsString('rebuildAndPersist', $local);
        self::assertStringContainsString('->clear($article)', $local);
    }

    public function test_backfill_command_is_idempotent_and_db_only(): void
    {
        $cmd = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/src/Console/BackfillArticleFeaturedImageProjectionCommand.php',
        );

        self::assertStringContainsString('seo:article-featured-image-projection-backfill', $cmd);
        self::assertStringContainsString('already_valid', $cmd);
        self::assertStringContainsString('conflicts', $cmd);
        self::assertStringContainsString('by_source', $cmd);
        self::assertStringContainsString('--dry-run', $cmd);
        self::assertStringNotContainsString('Http::', $cmd);
        self::assertStringNotContainsString('fetchFromWordPress', $cmd);
    }

    public function test_unknown_placeholder_distinct_from_absent_in_view(): void
    {
        $view = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/filament/tables/columns/article-list-thumbnail.blade.php'),
        );

        self::assertStringContainsString("status === 'available'", $view);
        self::assertStringContainsString("status === 'absent'", $view);
        self::assertStringContainsString('thumb_unknown', $view);
        self::assertStringContainsString('thumb_absent', $view);
        self::assertStringContainsString('placeholder--unknown', $view);
    }

    public function test_provider_registers_command_and_projection_singleton(): void
    {
        $provider = (string) file_get_contents(LegacyAddonPath::resolve('SeoContentAiServiceProvider.php'));

        self::assertStringContainsString('BackfillArticleFeaturedImageProjectionCommand::class', $provider);
        self::assertStringContainsString('ArticleFeaturedImageProjection::class', $provider);
    }

    public function test_editor_snapshot_rebuilds_from_stored_sources(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleEditor/ArticleEditorMediaSnapshotService.php',
        );

        self::assertStringContainsString('rebuildFromStoredSources', $source);
    }

    public function test_status_and_source_constants_cover_policy(): void
    {
        $status = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Support/ArticleFeaturedImageStatus.php',
        );
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Support/ArticleFeaturedImageSource.php',
        );

        self::assertStringContainsString("AVAILABLE = 'available'", $status);
        self::assertStringContainsString("ABSENT = 'absent'", $status);
        self::assertStringContainsString("UNKNOWN = 'unknown'", $status);
        self::assertStringContainsString('EDITOR_LOCAL', $source);
        self::assertStringContainsString('WP_SNAPSHOT', $source);
        self::assertStringContainsString('SEO_MEDIA', $source);
        self::assertStringContainsString('CONFLICT_RESOLVED', $source);
    }
}
