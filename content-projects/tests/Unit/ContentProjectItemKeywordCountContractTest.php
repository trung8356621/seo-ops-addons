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

    public function test_ops_table_renders_keywords_column_between_generation_and_workflow(): void
    {
        $list = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-items-list.blade.php'),
        );

        $gen = strpos($list, "ops_col_generation");
        $kw = strpos($list, "ops_col_keywords");
        $wf = strpos($list, "ops_col_workflow");
        self::assertNotFalse($gen);
        self::assertNotFalse($kw);
        self::assertNotFalse($wf);
        self::assertLessThan($kw, $gen);
        self::assertLessThan($wf, $kw);
        self::assertStringContainsString('keywords_count', $list);
        self::assertStringContainsString('(int) ($row[\'keywords_count\'] ?? 0)', $list);
    }

    public function test_missing_keywords_display_zero_not_dash(): void
    {
        $list = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-items-list.blade.php'),
        );
        $kwPos = strpos($list, 'cp-ops-kw-count');
        self::assertNotFalse($kwPos);
        $chunk = substr($list, $kwPos, 400);
        self::assertStringNotContainsString("'—'", $chunk);
        self::assertStringContainsString('(int) ($row[\'keywords_count\'] ?? 0)', $list);
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
