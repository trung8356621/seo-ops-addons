<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ContentProjectArchivePreview;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchiveItem;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ArchivePreviewArticlePresenter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Guard UI archive preview: slide-over, edit links, missing article, no N+1 batch load.
 */
final class ArchivePreviewArticleUiTest extends TestCase
{
    public function test_preview_page_uses_filament_slide_over_action(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(ContentProjectArchivePreview::class))->getFileName());

        self::assertStringContainsString('viewArchiveItemAction', $source);
        self::assertStringContainsString('->slideOver()', $source);
        self::assertStringContainsString('stickyModalHeader', $source);
        self::assertStringContainsString('archive-preview-item-slideover', $source);
        self::assertStringContainsString('ArchivePreviewArticlePresenter', $source);
    }

    public function test_preview_blade_title_links_and_mount_action(): void
    {
        $viewPath = LegacyAddonPath::resolve('resources/views/filament/resources/seo-project-resource/pages/content-project-archive-preview.blade.php');
        $view = (string) file_get_contents($viewPath);

        self::assertStringContainsString("mountAction('viewArchiveItem'", $view);
        self::assertStringContainsString('target="_blank"', $view);
        self::assertStringContainsString('rel="noopener noreferrer"', $view);
        self::assertStringContainsString('archive_preview_article_missing', $view);
        self::assertStringContainsString('has_public_wordpress_url', $view);
        self::assertStringContainsString('wordpress_url', $view);
        self::assertStringContainsString('markArticleIndexed', $view);
        self::assertStringContainsString('archive_preview_copy_link', $view);
        self::assertStringContainsString('navigator.clipboard.writeText', $view);
        self::assertStringContainsString('text-primary-600', $view);
        self::assertStringContainsString('archive_preview_col_int', $view);
        self::assertStringContainsString('archive_preview_col_ext', $view);
        self::assertStringContainsString('archive_preview_col_index', $view);
        self::assertStringContainsString('internal_link_count', $view);
        self::assertStringContainsString('w-full', $view);
        self::assertStringNotContainsString('x-teleport="body"', $view);
        self::assertStringNotContainsString('fixed inset-0 z-[80]', $view);
        self::assertStringNotContainsString("ArticleResource::getUrl('edit'", $view);
    }

    public function test_slideover_partial_has_sections(): void
    {
        $viewPath = LegacyAddonPath::resolve('resources/views/filament/resources/seo-project-resource/partials/archive-preview-item-slideover.blade.php');
        $view = (string) file_get_contents($viewPath);

        self::assertStringContainsString('archive_preview_section_main', $view);
        self::assertStringContainsString('archive_preview_section_seo', $view);
        self::assertStringContainsString('archive_preview_section_status', $view);
        self::assertStringContainsString('archive_preview_section_links', $view);
        self::assertStringContainsString('archive_preview_section_timestamps', $view);
        self::assertStringContainsString('archive_preview_section_excerpt', $view);
        self::assertStringContainsString('archive_preview_edit_article', $view);
    }

    public function test_presenter_resolves_edit_url_by_article_id_not_title(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(ArchivePreviewArticlePresenter::class))->getFileName());

        self::assertStringContainsString('ArticleResource::getUrl', $source);
        self::assertStringContainsString("'edit'", $source);
        self::assertStringContainsString('canAccessArticle', $source);
        self::assertStringContainsString('canEdit', $source);
        self::assertStringContainsString('loadArticlesById', $source);
        self::assertStringContainsString('whereIn', $source);
        self::assertStringNotContainsString('where(\'title\'', $source);
        self::assertStringNotContainsString('where(\'slug\'', $source);
    }

    public function test_presenter_marks_missing_article_without_edit_url(): void
    {
        $presenter = new ArchivePreviewArticlePresenter();
        $item = new SeoProjectArchiveItem([
            'id' => 11,
            'article_id' => 999001,
            'position' => 1,
            'article_snapshot' => [
                'article_id' => 999001,
                'title' => 'Ghost title',
                'primary_keyword' => 'kw',
                'status' => 'completed',
            ],
        ]);
        $item->id = 11;
        $item->setRelation('article', null);
        $item->setRelation('task', null);

        $row = $presenter->presentItem($item, collect());

        self::assertSame(11, $row['item_id']);
        self::assertSame(999001, $row['article_id']);
        self::assertSame('Ghost title', $row['title']);
        self::assertFalse($row['article_exists']);
        self::assertFalse($row['can_edit']);
        self::assertNull($row['edit_url']);
    }

    public function test_presenter_maps_existing_article_by_id_without_auth_container(): void
    {
        $presenter = new ArchivePreviewArticlePresenter();
        $article = new SeoArticle([
            'id' => 42,
            'title' => 'Live title',
            'site_id' => 7,
            'review_status' => 'approved',
            'internal_link_count' => 3,
            'external_link_count' => 1,
        ]);
        $article->id = 42;
        $article->setRelation('articleMetas', collect());

        $item = new SeoProjectArchiveItem([
            'id' => 5,
            'article_id' => 42,
            'position' => 2,
            'article_snapshot' => [
                'article_id' => 42,
                'title' => 'Snap title',
                'internal_link_count' => 3,
                'external_link_count' => 1,
            ],
        ]);
        $item->id = 5;
        $item->setRelation('task', null);

        // Pure PHPUnit: auth factory không bind → can_edit/edit_url null, vẫn map đúng article_id.
        $row = $presenter->presentItem($item, collect([42 => $article]));

        self::assertTrue($row['article_exists']);
        self::assertSame(42, $row['article_id']);
        self::assertSame('Snap title', $row['title']);
        self::assertSame(3, $row['internal_link_count']);
        self::assertSame(1, $row['external_link_count']);
        self::assertFalse($row['can_edit']);
        self::assertNull($row['edit_url']);
    }

    public function test_preview_page_hydrates_rows_via_batch_loader(): void
    {
        $method = new ReflectionMethod(ContentProjectArchivePreview::class, 'rebuildArticleRows');
        $source = $this->readMethodSource($method);

        self::assertStringContainsString('loadArticlesById', $source);
        self::assertStringContainsString('presentItems', $source);
    }

    public function test_preview_page_avoids_livewire_hydrate_method_prefix(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(ContentProjectArchivePreview::class))->getFileName());

        self::assertStringContainsString('rebuildArticleRows', $source);
        self::assertStringNotContainsString('function hydrateArticleRows', $source);
        self::assertStringNotContainsString('hydrateArticleRows()', $source);
    }

    public function test_article_resource_edit_url_helper_exists(): void
    {
        self::assertTrue(method_exists(ArticleResource::class, 'getUrl'));
        self::assertTrue(method_exists(ArticleResource::class, 'canEdit'));
        self::assertTrue(method_exists(ArticleResource::class, 'getRecordRouteBindingEloquentQuery'));
    }

    private function readMethodSource(ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }
}
