<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Services\MonthlyMcp\McpEligibleContentScope;
use PHPUnit\Framework\TestCase;

final class McpEligibleContentScopeTest extends TestCase
{
    public function test_eligible_wp_post_types_contains_post_and_product(): void
    {
        self::assertContains('post', McpEligibleContentScope::ELIGIBLE_WP_POST_TYPES);
        self::assertContains('product', McpEligibleContentScope::ELIGIBLE_WP_POST_TYPES);
    }

    public function test_eligible_wp_post_types_excludes_page(): void
    {
        self::assertNotContains('page', McpEligibleContentScope::ELIGIBLE_WP_POST_TYPES);
    }

    public function test_eligible_wp_post_types_excludes_custom_post_types(): void
    {
        self::assertNotContains('portfolio', McpEligibleContentScope::ELIGIBLE_WP_POST_TYPES);
        self::assertNotContains('case_study', McpEligibleContentScope::ELIGIBLE_WP_POST_TYPES);
        self::assertNotContains('event', McpEligibleContentScope::ELIGIBLE_WP_POST_TYPES);
    }

    public function test_meta_key_constant(): void
    {
        self::assertSame('wp_post_type', McpEligibleContentScope::META_KEY);
    }
}
