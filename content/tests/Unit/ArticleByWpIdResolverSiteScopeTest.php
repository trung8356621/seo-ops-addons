<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleByWpIdResolver;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * wp_post_id is NOT globally unique — identity is (site_id, wp_post_id).
 */
final class ArticleByWpIdResolverSiteScopeTest extends TestCase
{
    public function test_resolve_requires_site_argument(): void
    {
        $method = new ReflectionMethod(ArticleByWpIdResolver::class, 'resolve');
        $params = $method->getParameters();

        self::assertGreaterThanOrEqual(2, count($params));
        self::assertSame('site', $params[0]->getName());
        self::assertSame('wpId', $params[1]->getName());
    }

    public function test_resolve_source_always_scopes_by_site_id(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleByWpIdResolver::class))->getFileName(),
        );

        self::assertStringContainsString("->where('site_id', \$site->id)", $src);
        self::assertStringContainsString('whereWpPostId($wpId)', $src);
        // Must never resolve by wp id alone.
        self::assertDoesNotMatchRegularExpression(
            "/SeoArticle::query\(\)\s*->\s*whereWpPostId\(\$wpId\)\s*->\s*first\(\)/",
            $src,
        );
    }
}
