<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\SeoProjectArticleOwnerSyncService;
use PHPUnit\Framework\TestCase;

final class SeoProjectArticleOwnerSyncServiceTest extends TestCase
{
    public function test_it_normalizes_article_ids(): void
    {
        self::assertSame(
            [10, 20, 30],
            SeoProjectArticleOwnerSyncService::normalizeArticleIds([10, '20', 0, null, 20, 30, -1]),
        );
    }
}
