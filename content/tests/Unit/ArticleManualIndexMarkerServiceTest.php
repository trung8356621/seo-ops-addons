<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ContentProjectArchivePreview;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchiveItem;
use Omnichannel\Addons\Content\Services\ArticleManualIndexMarkerService;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectArchiveExportService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ArchivePreviewArticlePresenter;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Manual Index marker — rotate semantics + archive preview / export contracts.
 */
final class ArticleManualIndexMarkerServiceTest extends TestCase
{
    public function test_rotate_first_click_sets_indexed_keeps_previous_null(): void
    {
        $now = Carbon::parse('2026-08-08 10:00:00');
        $rotated = (new ArticleManualIndexMarkerService())->rotate(null, $now);

        self::assertTrue($rotated['indexed_at']->eq($now));
        self::assertNull($rotated['previous_indexed_at']);
    }

    public function test_rotate_second_click_moves_current_to_previous(): void
    {
        $t1 = Carbon::parse('2026-08-08 10:00:00');
        $t2 = Carbon::parse('2026-08-15 11:00:00');
        $rotated = (new ArticleManualIndexMarkerService())->rotate($t1, $t2);

        self::assertTrue($rotated['indexed_at']->eq($t2));
        self::assertNotNull($rotated['previous_indexed_at']);
        self::assertTrue($rotated['previous_indexed_at']->eq($t1));
    }

    public function test_rotate_third_click_keeps_only_two_latest(): void
    {
        $t2 = Carbon::parse('2026-08-15 11:00:00');
        $t3 = Carbon::parse('2026-09-02 09:00:00');
        $rotated = (new ArticleManualIndexMarkerService())->rotate($t2, $t3);

        self::assertTrue($rotated['indexed_at']->eq($t3));
        self::assertNotNull($rotated['previous_indexed_at']);
        self::assertTrue($rotated['previous_indexed_at']->eq($t2));
        self::assertFalse($rotated['previous_indexed_at']->eq(Carbon::parse('2026-08-08 10:00:00')));
    }

    public function test_mark_from_archive_item_is_atomic_and_does_not_restore_or_call_google(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(ArticleManualIndexMarkerService::class))->getFileName());

        self::assertStringContainsString("DB::connection('omi_seo_ai')->transaction", $source);
        self::assertStringContainsString('lockForUpdate', $source);
        self::assertStringContainsString('seo_project_archive_id', $source);
        self::assertStringContainsString('site_id', $source);
        self::assertStringContainsString('previous_indexed_at', $source);
        self::assertStringContainsString('article_snapshot', $source);
        self::assertStringContainsString("'project_restored' => false", $source);
        self::assertStringContainsString("'workspace_recreated' => false", $source);

        self::assertStringNotContainsString('SearchConsole', $source);
        self::assertStringNotContainsString('Indexing API', $source);
        self::assertStringNotContainsString('Google', $source);
        self::assertStringNotContainsString('->restore(', $source);
        self::assertStringNotContainsString('workspaceDestroyer', $source);
        self::assertStringNotContainsString('cleanupArchivedWorkspace', $source);
        self::assertStringNotContainsString('ArchiveContentProjectService', $source);
    }

    public function test_preview_page_mark_action_reuses_archive_auth_without_restore(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(ContentProjectArchivePreview::class))->getFileName());

        self::assertStringContainsString('function markArticleIndexed', $source);
        self::assertStringContainsString('ArticleManualIndexMarkerService', $source);
        self::assertStringContainsString('canViewProjectArchives', $source);
        self::assertStringContainsString('canAccessSite', $source);
        self::assertStringContainsString('markFromArchiveItem', $source);
        self::assertStringNotContainsString('->restore(', $source);
        self::assertStringNotContainsString('RestoreContentProject', $source);
    }

    public function test_presenter_links_only_valid_public_wordpress_url(): void
    {
        $presenter = new ArchivePreviewArticlePresenter();

        $withUrl = new SeoProjectArchiveItem([
            'id' => 1,
            'article_id' => 10,
            'position' => 1,
            'article_snapshot' => [
                'title' => 'Published',
                'wordpress_url' => 'https://example.com/hello/',
            ],
        ]);
        $withUrl->id = 1;
        $withUrl->setRelation('article', null);
        $withUrl->setRelation('task', null);

        $row = $presenter->presentItem($withUrl, collect());
        self::assertTrue($row['has_public_wordpress_url']);
        self::assertSame('https://example.com/hello/', $row['wordpress_url']);

        $withoutUrl = new SeoProjectArchiveItem([
            'id' => 2,
            'article_id' => 11,
            'position' => 2,
            'article_snapshot' => [
                'title' => 'No permalink',
                'wordpress_url' => '',
            ],
        ]);
        $withoutUrl->id = 2;
        $withoutUrl->setRelation('article', null);
        $withoutUrl->setRelation('task', null);

        $empty = $presenter->presentItem($withoutUrl, collect());
        self::assertFalse($empty['has_public_wordpress_url']);
        self::assertSame('', $empty['wordpress_url']);
        self::assertSame('No permalink', $empty['title']);
    }

    public function test_presenter_does_not_fabricate_wordpress_url_from_slug(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(ArchivePreviewArticlePresenter::class))->getFileName());

        self::assertStringContainsString('wp_permalink', $source);
        self::assertStringContainsString('isPublicHttpUrl', $source);
        self::assertStringNotContainsString('WordPressPermalinkBuilder', $source);
        self::assertStringNotContainsString('baseUrl', $source);
    }

    public function test_presenter_reads_live_article_index_timestamps(): void
    {
        $presenter = new ArchivePreviewArticlePresenter();
        $article = new SeoArticle([
            'id' => 42,
            'title' => 'Live',
            'site_id' => 7,
            'indexed_at' => Carbon::parse('2026-08-15 10:00:00'),
            'previous_indexed_at' => Carbon::parse('2026-08-08 10:00:00'),
        ]);
        $article->id = 42;
        $article->setRelation('articleMetas', collect());

        $item = new SeoProjectArchiveItem([
            'id' => 5,
            'article_id' => 42,
            'position' => 1,
            'article_snapshot' => [
                'title' => 'Snap',
                'indexed_at' => '2026-01-01T00:00:00+00:00',
                'previous_indexed_at' => null,
            ],
        ]);
        $item->id = 5;
        $item->setRelation('task', null);

        $row = $presenter->presentItem($item, collect([42 => $article]));

        self::assertSame('15/08/2026', $row['indexed_at_label']);
        self::assertSame('08/08/2026', $row['previous_indexed_at_label']);
    }

    public function test_export_includes_indexed_columns_and_formats_null_blank(): void
    {
        $ref = new ReflectionClass(ContentProjectArchiveExportService::class);
        $source = (string) file_get_contents((string) $ref->getFileName());

        self::assertStringContainsString("'indexed_at' => 'Index gần nhất'", $source);
        self::assertStringContainsString("'previous_indexed_at' => 'Index lần trước'", $source);
        self::assertStringContainsString('overlayManualIndexFields', $source);

        $stringify = new ReflectionMethod(ContentProjectArchiveExportService::class, 'stringifyCellValue');
        $stringify->setAccessible(true);
        $service = $ref->newInstanceWithoutConstructor();

        self::assertSame('', $stringify->invoke($service, null));
        self::assertSame('', $stringify->invoke($service, ''));

        $format = new ReflectionMethod(ContentProjectArchiveExportService::class, 'formatDateTime');
        $format->setAccessible(true);
        self::assertNull($format->invoke($service, null));
        self::assertSame(
            '2026-08-08 10:00:00',
            $format->invoke($service, Carbon::parse('2026-08-08 10:00:00')),
        );
    }

    public function test_seo_article_casts_manual_index_timestamps(): void
    {
        $article = new SeoArticle();
        $casts = $article->getCasts();

        self::assertSame('datetime', $casts['indexed_at'] ?? null);
        self::assertSame('datetime', $casts['previous_indexed_at'] ?? null);
    }
}
