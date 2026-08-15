<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Services\ArticleSeoSnapshotService;
use PHPUnit\Framework\TestCase;

final class ArticleSeoSnapshotStaleTest extends TestCase
{
    public function test_stale_when_content_hash_diverges(): void
    {
        $service = (new \ReflectionClass(ArticleSeoSnapshotService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ArticleSeoSnapshotService::class, 'isAnalysisStale');

        self::assertTrue($method->invoke($service, 'aaa', 'bbb'));
        self::assertFalse($method->invoke($service, 'aaa', 'aaa'));
        self::assertFalse($method->invoke($service, '', 'bbb'));
        self::assertFalse($method->invoke($service, 'aaa', ''));
    }
}
