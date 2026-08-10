<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;

/**
 * Guard: last_manual_saved_at / last_synced_at khÃƒÂ´ng lÃ¡ÂºÂ¥y tÃ¡Â»Â« updated_at.
 */
final class ArticleLastSavedTimestampWiringTest extends TestCase
{
    public function test_update_article_content_action_only_touches_manual_for_editor_origin(): void
    {
        $source = file_get_contents(
            ProjectRoot::addonsPath().'/agent/src/Automation/Actions/Article/UpdateArticleContentAction.php'
        );
        self::assertNotFalse($source);
        self::assertStringContainsString('ArticleLastSavedTimestampService', $source);
        self::assertStringContainsString('shouldTouchManualFromOrigin', $source);
        self::assertStringContainsString('touchManualSaved', $source);
    }

    public function test_wordpress_sync_success_touches_synced_timestamp(): void
    {
        $sync = file_get_contents(
            ProjectRoot::addonsPath().'/wordpress/src/Services/WordPressArticleSyncService.php'
        );
        $domain = file_get_contents(
            ProjectRoot::addonsPath().'/wordpress/src/Services/SyncDomainContentService.php'
        );
        $pull = file_get_contents(
            ProjectRoot::addonsPath().'/wordpress/src/Services/WordPressArticleContentService.php'
        );

        self::assertNotFalse($sync);
        self::assertNotFalse($domain);
        self::assertNotFalse($pull);
        self::assertStringContainsString('touchSynced', $sync);
        self::assertStringContainsString('touchSynced', $domain);
        self::assertStringContainsString('touchSynced', $pull);
    }

    public function test_migration_adds_dedicated_columns_without_updated_at_backfill(): void
    {
        $migration = file_get_contents(
            \Omnichannel\Addons\Seo\Support\SeoMigrationPath::find('2026_07_24_100000_add_last_saved_timestamps_to_articles_table.php')
        );
        self::assertNotFalse($migration);
        self::assertStringContainsString("timestamp('last_manual_saved_at')", $migration);
        self::assertStringContainsString("timestamp('last_synced_at')", $migration);
        self::assertStringNotContainsString('updated_at', $migration);
        self::assertStringNotContainsString('backfill', strtolower($migration));
    }

    public function test_manual_save_origins_exclude_automation(): void
    {
        $source = file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleLastSavedTimestampService.php'
        );
        self::assertNotFalse($source);
        self::assertStringContainsString("'article_editor'", $source);
        self::assertStringNotContainsString('migration.project_article_content_update', $source);
    }
}
