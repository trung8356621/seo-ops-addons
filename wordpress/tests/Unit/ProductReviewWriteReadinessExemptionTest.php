<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Unit;

use Omnichannel\Addons\WordPress\Services\WordPressWriteReadinessGuard;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ProductReviewWriteReadinessExemptionTest extends TestCase
{
    public function test_product_review_sync_is_exempt_from_media_slug_fix(): void
    {
        $guard = (new ReflectionClass(WordPressWriteReadinessGuard::class))
            ->newInstanceWithoutConstructor();

        $method = new ReflectionMethod(WordPressWriteReadinessGuard::class, 'isExemptFromMediaSlugFix');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($guard, 'wordpress.product_review.sync'));
        self::assertFalse($method->invoke($guard, 'wordpress.article.sync'));
        self::assertFalse($method->invoke($guard, 'wordpress.media.upload'));
    }
}
