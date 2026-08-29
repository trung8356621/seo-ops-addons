<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;
use Tests\Support\ProjectRoot;

final class ArticleListTableLoadingRegressionTest extends TestCase
{
    public function test_article_list_keeps_canonical_overlay_implementation(): void
    {
        $blade = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/article-resource/pages/list-articles.blade.php',
        ));
        $js = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/articleListTableLoading.js',
        );

        self::assertStringContainsString('article-list-table-shell', $blade);
        self::assertStringContainsString('article-list-table-shell__overlay', $blade);
        self::assertStringContainsString('is-table-loading', $blade);
        self::assertStringContainsString("Livewire.hook('commit'", $js);
        self::assertStringContainsString('.article-list-table-shell', $js);
        self::assertStringContainsString('SeoPanelLoading?.beginBar', $js);
        self::assertStringContainsString('SeoPanelLoading?.endBar', $js);
        self::assertStringNotContainsString('list-table-loading-shell', $blade);
    }
}
