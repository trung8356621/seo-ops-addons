<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Support\CommentReviewRatingAssigner;
use PHPUnit\Framework\TestCase;

final class CommentReviewRatingAssignerTest extends TestCase
{
    public function test_cycles_two_five_stars_and_one_four_star(): void
    {
        $assigner = new CommentReviewRatingAssigner();

        $this->assertSame(5, $assigner->resolve(null, 0));
        $this->assertSame(5, $assigner->resolve(null, 1));
        $this->assertSame(4, $assigner->resolve(null, 2));
        $this->assertSame(5, $assigner->resolve(null, 3));
    }

    public function test_respects_explicit_rating(): void
    {
        $assigner = new CommentReviewRatingAssigner();

        $this->assertSame(3, $assigner->resolve(3, 0));
        $this->assertSame(5, $assigner->resolve(9, 1));
    }
}
