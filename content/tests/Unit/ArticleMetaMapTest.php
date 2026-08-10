<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleMetaMap;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * No DB needed: articleMetas relation is set manually via setRelation() so
 * ArticleMetaMap::for()'s loadMissing() sees it as already loaded and never queries.
 */
final class ArticleMetaMapTest extends TestCase
{
    public function test_get_reads_from_already_loaded_relation_without_query(): void
    {
        $article = $this->articleWithMetas([
            'wp_post_content' => '<p>Cached body</p>',
            'loai_san_pham' => 'Điện tử',
        ]);

        $map = ArticleMetaMap::for($article);

        self::assertSame('<p>Cached body</p>', $map->get('wp_post_content'));
        self::assertSame('Điện tử', $map->get('loai_san_pham'));
        self::assertNull($map->get('missing_key'));
        self::assertSame('fallback', $map->get('missing_key', 'fallback'));
    }

    public function test_has_reflects_relation_contents(): void
    {
        $article = $this->articleWithMetas(['category_ids' => '[1,2,3]']);
        $map = ArticleMetaMap::for($article);

        self::assertTrue($map->has('category_ids'));
        self::assertFalse($map->has('wp_category_ids'));
    }

    public function test_get_json_decodes_value(): void
    {
        $article = $this->articleWithMetas(['category_ids' => '[1,2,3]', 'blank' => '']);
        $map = ArticleMetaMap::for($article);

        self::assertSame([1, 2, 3], $map->getJson('category_ids'));
        self::assertNull($map->getJson('blank'));
        self::assertNull($map->getJson('missing_key'));
    }

    public function test_get_any_falls_back_through_candidate_keys_in_order(): void
    {
        $article = $this->articleWithMetas(['wp_category_ids' => '[9]']);
        $map = ArticleMetaMap::for($article);

        self::assertSame('[9]', $map->getAny(['category_ids', 'wp_category_ids']));

        $none = ArticleMetaMap::for($this->articleWithMetas([]));
        self::assertSame('', $none->getAny(['category_ids', 'wp_category_ids'], ''));
    }

    /**
     * @param  array<string, string>  $metas
     */
    private function articleWithMetas(array $metas): SeoArticle
    {
        $article = new SeoArticle;

        $rows = new Collection(
            array_map(
                static fn (string $key, string $value): ArticleMeta => new ArticleMeta([
                    'meta_key' => $key,
                    'meta_value' => $value,
                ]),
                array_keys($metas),
                array_values($metas),
            ),
        );

        $article->setRelation('articleMetas', $rows);

        return $article;
    }
}
