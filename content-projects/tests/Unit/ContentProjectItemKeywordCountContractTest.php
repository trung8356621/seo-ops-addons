<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\Content\Support\ArticleKeywordDistinctCounter;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemOperationsReadModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

final class ContentProjectItemKeywordCountContractTest extends TestCase
{
    public function test_read_model_uses_canonical_seo_article_keywords_meta(): void
    {
        $src = $this->source(ContentProjectItemOperationsReadModel::class);

        self::assertStringContainsString('ArticleKeywordDistinctCounter', $src);
        self::assertStringContainsString('ArticleKeywordDistinctCounter::META_KEY', $src);
        self::assertStringContainsString('keywords_count', $src);
        self::assertStringContainsString('distinctKeywordCount', $src);
        self::assertStringNotContainsString("\$article->body", $src);
        self::assertStringNotContainsString('wp_post_content', $src);
        self::assertStringNotContainsString('strip_tags', $src);
    }

    public function test_eager_load_includes_keyword_meta_without_body(): void
    {
        $src = $this->source(ContentProjectItemOperationsReadModel::class);
        self::assertStringContainsString("'article.articleMetas'", $src);
        self::assertStringContainsString('ArticleKeywordDistinctCounter::META_KEY', $src);
        self::assertSame('seo_article_keywords', ArticleKeywordDistinctCounter::META_KEY);
    }

    public function test_ops_table_renders_keywords_column_before_workflow(): void
    {
        $list = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-items-list.blade.php'),
        );
        $cell = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-keyword-cell.blade.php'),
        );

        $kw = strpos($list, 'ops_col_keywords');
        $wf = strpos($list, 'ops_col_workflow');
        self::assertNotFalse($kw);
        self::assertNotFalse($wf);
        self::assertLessThan($wf, $kw);
        self::assertStringNotContainsString('ops_col_generation', $list);
        self::assertStringContainsString('generation_badge', $list);
        self::assertStringContainsString('content-project-keyword-cell', $list);
        self::assertStringContainsString('cp-ops-kw-cell', $cell);
    }

    public function test_missing_keywords_display_zero_not_dash(): void
    {
        $src = $this->source(ContentProjectItemOperationsReadModel::class);
        self::assertStringContainsString('keywords_count', $src);

        $list = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-items-list.blade.php'),
        );
        self::assertStringContainsString('content-project-keyword-cell', $list);
        // Count must not fall back to em-dash in the ops table.
        self::assertStringNotContainsString("keywords_count'] ?? 0) ?: '—'", $list);
    }

    /**
     * @param  class-string  $class
     */
    private function source(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        self::assertNotFalse($file);

        return (string) file_get_contents($file);
    }
}
