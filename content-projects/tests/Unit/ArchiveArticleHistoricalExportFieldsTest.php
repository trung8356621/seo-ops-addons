<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectArchiveItem;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ArchiveArticleHistoricalFieldResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ArchivePreviewArticlePresenter;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectArchivedMonthExportAssembler;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemDomainResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate\ExcelDetailRowValueResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelTemplate\ExcelDetailColumnRegistry;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArchivedMonthlyWorkloadService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ArchiveArticleHistoricalExportFieldsTest extends TestCase
{
    public function test_resolver_uses_snapshot_when_live_article_missing(): void
    {
        $resolver = new ArchiveArticleHistoricalFieldResolver();
        $fields = $resolver->resolve(null, [
            'title' => 'Nghệ thuật Typography: Bí quyết tạo nên sức hút cho thiết kế chuyên nghiệp',
            'primary_keyword' => 'typography chuyên nghiệp',
            'wordpress_url' => 'https://example.com/typography',
            'reviewed_at' => '2026-07-10T10:00:00+00:00',
            'indexed_at' => '2026-07-11T00:00:00+00:00',
        ]);

        self::assertSame(
            'Nghệ thuật Typography: Bí quyết tạo nên sức hút cho thiết kế chuyên nghiệp',
            $fields['title'],
        );
        self::assertSame('typography chuyên nghiệp', $fields['keyword']);
        self::assertSame('https://example.com/typography', $fields['wordpress_url']);
        self::assertNotNull($fields['reviewed_at']);
        self::assertNotNull($fields['indexed_at']);
    }

    public function test_resolver_prefers_snapshot_title_over_live_when_both_present(): void
    {
        $article = new SeoArticle();
        $article->forceFill(['title' => 'Live edited title']);
        $article->setRelation('articleMetas', new EloquentCollection());
        $article->setRelation('wordpressLink', null);
        $article->setRelation('seoProfile', null);

        $resolver = new ArchiveArticleHistoricalFieldResolver();
        $fields = $resolver->resolve($article, [
            'title' => 'Phong cách Office Chic',
            'primary_keyword' => 'office chic',
        ]);

        self::assertSame('Phong cách Office Chic', $fields['title']);
        self::assertSame('office chic', $fields['keyword']);
    }

    public function test_preview_presenter_matches_resolver_for_missing_article(): void
    {
        $item = new SeoProjectArchiveItem();
        $item->forceFill([
            'id' => 11,
            'article_id' => 999001,
            'position' => 1,
            'article_snapshot' => [
                'article_id' => 999001,
                'title' => 'Phong cách Office Chic',
                'primary_keyword' => 'office chic',
                'wordpress_url' => 'https://example.com/office-chic',
            ],
        ]);
        $item->setRelation('article', null);

        $row = (new ArchivePreviewArticlePresenter())->presentItem($item, collect());

        self::assertFalse($row['article_exists']);
        self::assertSame('Phong cách Office Chic', $row['title']);
        self::assertSame('office chic', $row['keyword']);

        $exportFields = (new ArchiveArticleHistoricalFieldResolver())->resolve(
            null,
            is_array($item->article_snapshot) ? $item->article_snapshot : [],
        );
        self::assertSame($row['title'], $exportFields['title']);
        self::assertSame($row['keyword'], $exportFields['keyword']);
    }

    public function test_assembler_and_excel_resolver_keep_historical_title_keyword(): void
    {
        $assembler = new ContentProjectArchivedMonthExportAssembler(new ContentProjectItemDomainResolver());
        $payload = $assembler->assemble(
            '2026-07',
            '07/2026',
            [[
                'writer_id' => 5,
                'writer_name' => 'Triều Nguyễn Hữu',
                'project_name' => 'Project A',
                'site_id' => 1,
                'article_id' => 999001,
                'title' => 'Phong cách Office Chic',
                'keyword' => 'office chic',
                'wordpress_url' => 'https://example.com/office-chic',
                'post_type' => 'Post',
                'plan' => 'Create',
                'index_status' => 'Indexed',
                'reviewed_at' => '10/07/2026',
                'archived_by' => 'Admin',
            ]],
            [1 => 'a.com'],
        );

        $row = $payload['writer_sheets'][0]['rows'][0];
        self::assertSame('Phong cách Office Chic', $row['title']);
        self::assertSame('office chic', $row['keyword']);

        $excel = new ExcelDetailRowValueResolver();
        $titleCell = $excel->resolve($row, ExcelDetailColumnRegistry::CODE_ARTICLE_TITLE);
        self::assertIsString($titleCell);
        self::assertStringContainsString('Phong cách Office Chic', (string) $titleCell);
        self::assertSame(
            'office chic',
            $excel->resolve($row, ExcelDetailColumnRegistry::CODE_KEYWORD),
        );
        self::assertSame(
            'Triều Nguyễn Hữu',
            $excel->resolve($row, ExcelDetailColumnRegistry::CODE_WRITER_NAME, 'Triều Nguyễn Hữu'),
        );
    }

    public function test_workload_item_rows_uses_historical_field_resolver(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectArchivedMonthlyWorkloadService::class))->getFileName(),
        );

        self::assertStringContainsString('ArchiveArticleHistoricalFieldResolver', $src);
        self::assertStringContainsString('hydrateArchiveItemMaps', $src);
        self::assertStringContainsString('article_snapshot', $src);
        self::assertStringNotContainsString('resolveArticleExportFields', $src);
        self::assertStringNotContainsString("'title' => trim((string) (\$row->title", $src);
    }
}
