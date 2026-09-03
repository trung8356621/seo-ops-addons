<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArchivedMonthExportService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectArchiveSocialExportRowExpander;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ExcelHyperlinkHelper;
use Omnichannel\Addons\Social\Services\ArticleSocialLinkService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Monthly Archived-YYYY-MM.xlsx must emit social evidence child rows like per-project export.
 */
final class ContentProjectArchivedMonthSocialExportTest extends TestCase
{
    public function test_month_export_service_loads_social_links_once_and_expands_writer_sheets(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectArchivedMonthExportService::class))->getFileName(),
        );

        self::assertStringContainsString(ArticleSocialLinkService::class, $src);
        self::assertStringContainsString('linksGroupedByArticle', $src);
        self::assertStringContainsString('appendSocialEvidenceRows', $src);
        self::assertStringContainsString(ContentProjectArchiveSocialExportRowExpander::class, $src);
        self::assertStringContainsString('hyperlink_url', $src);
    }

    public function test_expander_emits_parent_then_social_children_in_order(): void
    {
        $expander = new ContentProjectArchiveSocialExportRowExpander();

        $rows = [
            $this->articleRow(101, 'Article A', 'https://example.com/a'),
            $this->articleRow(202, 'Article B', 'https://example.com/b'),
        ];

        $linksByArticle = [
            101 => [
                [
                    'id' => 1,
                    'url' => 'https://facebook.com/post/abc',
                    'domain' => 'facebook.com',
                    'recorded_at' => '01/09/2026',
                ],
                [
                    'id' => 2,
                    'url' => 'https://reddit.com/r/test/comments/xyz',
                    'domain' => 'reddit.com',
                    'recorded_at' => '02/09/2026',
                ],
            ],
        ];

        $expanded = $expander->expandWriterSheetRows($rows, $linksByArticle);

        self::assertCount(4, $expanded);
        self::assertSame(101, (int) $expanded[0]['article_id']);
        self::assertSame('Article A', $expanded[0]['title']);
        self::assertSame('https://example.com/a', $expanded[0]['wordpress_url']);

        self::assertSame('social', $expanded[1]['row_kind']);
        self::assertSame('', $expanded[1]['project']);
        self::assertSame('', $expanded[1]['domain']);
        self::assertStringContainsString('facebook.com', $expanded[1]['title']);
        self::assertStringContainsString('↳', $expanded[1]['title']);
        self::assertSame('https://facebook.com/post/abc', $expanded[1]['hyperlink_url']);
        self::assertSame('01/09/2026', $expanded[1]['reviewed_at']);
        self::assertSame('', $expanded[1]['keyword']);

        self::assertSame('social', $expanded[2]['row_kind']);
        self::assertStringContainsString('reddit.com', $expanded[2]['title']);
        self::assertSame('https://reddit.com/r/test/comments/xyz', $expanded[2]['hyperlink_url']);

        self::assertSame(202, (int) $expanded[3]['article_id']);
        self::assertSame('Article B', $expanded[3]['title']);
    }

    public function test_social_child_title_renders_as_hyperlink_formula(): void
    {
        $expander = new ContentProjectArchiveSocialExportRowExpander();

        $expanded = $expander->expandWriterSheetRows(
            [$this->articleRow(55, 'Parent', 'https://wp.example/post')],
            [
                55 => [
                    [
                        'id' => 9,
                        'url' => 'https://facebook.com/share/1',
                        'domain' => 'facebook.com',
                        'recorded_at' => '01/09/2026',
                    ],
                ],
            ],
        );

        $child = $expanded[1];
        $formula = ExcelHyperlinkHelper::formula(
            (string) $child['hyperlink_url'],
            (string) $child['title'],
        );

        self::assertSame(
            '=HYPERLINK("https://facebook.com/share/1","↳ facebook.com — https://facebook.com/share/1")',
            $formula,
        );

        $parentFormula = ExcelHyperlinkHelper::formula(
            'https://wp.example/post',
            'Parent',
        );
        self::assertSame(
            '=HYPERLINK("https://wp.example/post","Parent")',
            $parentFormula,
        );
    }

    public function test_append_social_evidence_collects_article_ids_once_per_payload(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectArchivedMonthExportService::class))->getFileName(),
        );

        self::assertStringContainsString('function appendSocialEvidenceRows', $src);
        self::assertSame(1, substr_count($src, 'linksGroupedByArticle('));
    }

    public function test_workload_item_rows_include_article_id_for_social_projection(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArchivedMonthlyWorkloadService::class,
            ))->getFileName(),
        );

        self::assertStringContainsString("'article_id' => \$resolvedArticleId", $src);
    }

    /**
     * @return array<string, mixed>
     */
    private function articleRow(int $articleId, string $title, string $wordpressUrl): array
    {
        return [
            'project' => '7/2026',
            'domain' => 'maytuicanvas.com',
            'title' => $title,
            'article_id' => $articleId,
            'keyword' => 'keyword',
            'wordpress_url' => $wordpressUrl,
            'post_type' => 'Post',
            'plan' => 'Create',
            'index_status' => 'Chưa index',
            'reviewed_at' => '31/08/2026',
            'archived_by' => 'Admin',
        ];
    }
}
