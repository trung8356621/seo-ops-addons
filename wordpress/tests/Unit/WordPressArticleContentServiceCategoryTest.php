<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Unit;

use Omnichannel\Addons\WordPress\Services\WordPressArticleContentService;
use Tests\TestCase;

final class WordPressArticleContentServiceCategoryTest extends TestCase
{
    public function test_extract_category_ids_from_post(): void
    {
        $service = app(WordPressArticleContentService::class);

        $this->assertSame([12, 34], $service->extractCategoryIdsFromPost([
            'category_ids' => [12, '34', 0, 12],
        ]));

        $this->assertSame([], $service->extractCategoryIdsFromPost([
            'category_ids' => 'invalid',
        ]));
    }
}
