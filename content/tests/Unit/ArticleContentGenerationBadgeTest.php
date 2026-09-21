<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Support\ArticleContentGenerationBadge;
use PHPUnit\Framework\TestCase;

final class ArticleContentGenerationBadgeTest extends TestCase
{
    public function test_free_plus_split_shows_badge(): void
    {
        $out = ArticleContentGenerationBadge::fromSnapshot([
            'hook_key' => 'content.generate',
            'generation_shape' => 'sectioned',
            'shape_decision_cost_class' => 'free',
        ]);

        self::assertTrue($out['show_free_badge']);
        self::assertSame('free', $out['route_cost']);
        self::assertSame('sectioned', $out['generation_shape']);
    }

    public function test_paid_plus_single_hides_badge(): void
    {
        $out = ArticleContentGenerationBadge::fromSnapshot([
            'hook_key' => 'content.generate',
            'generation_shape' => 'single_pass',
            'shape_decision_cost_class' => 'paid',
        ]);

        self::assertFalse($out['show_free_badge']);
        self::assertSame('paid', $out['route_cost']);
        self::assertSame('single_pass', $out['generation_shape']);
    }

    public function test_free_plus_single_hides_badge(): void
    {
        $out = ArticleContentGenerationBadge::fromSnapshot([
            'hook_key' => 'content.generate',
            'generation_shape' => 'single_pass',
            'shape_decision_cost_class' => 'free',
        ]);

        self::assertFalse($out['show_free_badge']);
        self::assertSame('free', $out['route_cost']);
        self::assertSame('single_pass', $out['generation_shape']);
    }

    public function test_paid_plus_split_hides_badge(): void
    {
        $out = ArticleContentGenerationBadge::fromSnapshot([
            'hook_key' => 'content.generate',
            'generation_shape' => 'sectioned',
            'shape_decision_cost_class' => 'paid',
        ]);

        self::assertFalse($out['show_free_badge']);
        self::assertSame('paid', $out['route_cost']);
        self::assertSame('sectioned', $out['generation_shape']);
    }

    public function test_missing_legacy_metadata_hides_badge(): void
    {
        $empty = ArticleContentGenerationBadge::fromSnapshot([]);
        self::assertFalse($empty['show_free_badge']);
        self::assertNull($empty['route_cost']);
        self::assertNull($empty['generation_shape']);

        $shapeOnly = ArticleContentGenerationBadge::fromSnapshot([
            'hook_key' => 'content.generate',
            'generation_shape' => 'sectioned',
        ]);
        self::assertFalse($shapeOnly['show_free_badge']);
        self::assertNull($shapeOnly['route_cost']);
        self::assertNull($shapeOnly['generation_shape']);

        $costOnly = ArticleContentGenerationBadge::fromSnapshot([
            'hook_key' => 'content.generate',
            'shape_decision_cost_class' => 'free',
        ]);
        self::assertFalse($costOnly['show_free_badge']);
        self::assertNull($costOnly['route_cost']);
        self::assertNull($costOnly['generation_shape']);
    }

    public function test_primary_is_free_true_with_sectioned_orchestrator_shows_badge(): void
    {
        // Real article 13637 orchestrator stamp: shape + primary_is_free, no shape_decision_cost_class.
        $out = ArticleContentGenerationBadge::fromSnapshot([
            'hook_key' => 'article.content.generate',
            'generation_shape' => 'sectioned',
            'generation_shape_source' => 'route_cost_auto',
            'primary_is_free' => true,
            'pass_mode' => 'multiple_pass',
            'sectioned_free_orchestrator' => true,
        ]);

        self::assertTrue($out['show_free_badge']);
        self::assertSame('free', $out['route_cost']);
        self::assertSame('sectioned', $out['generation_shape']);
    }

    public function test_primary_is_free_false_with_sectioned_does_not_show_badge(): void
    {
        $out = ArticleContentGenerationBadge::fromSnapshot([
            'hook_key' => 'content.generate',
            'generation_shape' => 'sectioned',
            'primary_is_free' => false,
        ]);

        self::assertFalse($out['show_free_badge']);
        self::assertSame('paid', $out['route_cost']);
    }

    public function test_non_content_hook_hides_badge_even_when_free_split(): void
    {
        $out = ArticleContentGenerationBadge::fromSnapshot([
            'hook_key' => 'outline.generate',
            'generation_shape' => 'sectioned',
            'shape_decision_cost_class' => 'free',
        ]);

        self::assertFalse($out['show_free_badge']);
        self::assertSame(ArticleContentGenerationBadge::empty(), $out);
    }
}
