<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;

final class ContentProjectArchiveSocialColumnTest extends TestCase
{
    public function test_archive_preview_drops_legacy_columns_and_reuses_social_share_actions(): void
    {
        $viewPath = LegacyAddonPath::resolve(
            'resources/views/filament/resources/seo-project-resource/pages/content-project-archive-preview.blade.php'
        );
        $view = (string) file_get_contents($viewPath);

        self::assertStringNotContainsString('archive_preview_col_keyword', $view);
        self::assertStringNotContainsString('archive_col_status', $view);
        self::assertStringNotContainsString('archive_preview_col_int', $view);
        self::assertStringNotContainsString('archive_preview_col_ext', $view);
        self::assertStringNotContainsString('archive_preview_col_sync', $view);

        self::assertStringContainsString('archive_preview_col_index', $view);
        self::assertStringContainsString('archive_preview_col_social', $view);
        self::assertStringContainsString('x-seo-content-ai::social-share-actions', $view);
        self::assertStringContainsString('archive_preview_copy_link', $view);
        self::assertStringContainsString('colspan="6"', $view);
        self::assertStringNotContainsString('site-id', $view);
        self::assertStringNotContainsString('not_configured', $view);
        self::assertStringNotContainsString('Thiết lập', $view);
    }

    public function test_gsc_mcp_drawer_reuses_same_social_share_actions_component(): void
    {
        $drawerPath = LegacyAddonPath::resolve(
            'resources/views/seo/performance-hub/partials/gsc-mcp-drawer.blade.php'
        );
        $drawer = (string) file_get_contents($drawerPath);

        self::assertStringContainsString('gsc_social_top10_title', $drawer);
        self::assertStringContainsString('x-seo-content-ai::social-share-actions', $drawer);
        self::assertStringNotContainsString('site-id', $drawer);
        self::assertStringNotContainsString('gsc-social-buttons', $drawer);
    }
}
