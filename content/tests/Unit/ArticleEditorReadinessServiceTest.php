<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleEditorReadinessService;
use PHPUnit\Framework\TestCase;

final class ArticleEditorReadinessServiceTest extends TestCase
{
    public function test_body_hash_is_stable_for_same_content(): void
    {
        $service = new ArticleEditorReadinessService;
        $article = new \Omnichannel\Addons\Content\Models\SeoArticle([
            'body' => '<p>Hello world</p>',
        ]);

        self::assertSame(
            $service->bodyHash($article),
            $service->bodyHash($article),
        );
    }
}
