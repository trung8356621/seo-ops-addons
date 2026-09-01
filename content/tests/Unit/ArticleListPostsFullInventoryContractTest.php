<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

/**
 * Posts tab = full WP inventory: do not strip skip_seo_audit or approved/archived.
 */
final class ArticleListPostsFullInventoryContractTest extends TestCase
{
    public function test_posts_tab_does_not_apply_skip_or_unreviewed_scopes(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/ListArticles.php',
        );

        self::assertStringContainsString(
            'Posts = full WP inventory',
            $source,
            'Posts full-inventory policy comment must remain as the contract anchor',
        );

        self::assertStringContainsString(
            'if ($this->contentTab !== self::TAB_POSTS) {',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/if \(\$this->contentTab !== self::TAB_POSTS\) \{\s*ArticleResource::applyExcludeSkipSeoAuditScope\(\$query\);/s',
            $source,
            'skip_seo_audit exclusion must not run on Posts tab',
        );

        self::assertDoesNotMatchRegularExpression(
            '/in_array\(\$this->contentTab,\s*\[self::TAB_POSTS,\s*self::TAB_CATEGORIES,\s*self::TAB_QUEUE\],\s*true\)/',
            $source,
            'Posts must not be in the unreviewed-scope tab list',
        );
        self::assertMatchesRegularExpression(
            '/in_array\(\$this->contentTab,\s*\[self::TAB_CATEGORIES,\s*self::TAB_QUEUE\],\s*true\)/',
            $source,
            'Categories/Queue still use unreviewed scope',
        );
    }
}
