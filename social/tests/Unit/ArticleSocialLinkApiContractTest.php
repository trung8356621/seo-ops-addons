<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Social\Tests\Unit;

use Omnichannel\Addons\Social\Http\Controllers\ArticleSocialLinkController;
use Omnichannel\Addons\Social\Services\ArticleSocialLinkService;
use PHPUnit\Framework\TestCase;

final class ArticleSocialLinkApiContractTest extends TestCase
{
    public function test_api_routes_registered_on_existing_seo_articles_prefix(): void
    {
        $provider = (string) file_get_contents(
            dirname(__DIR__, 3).'/seo-content-ai-compat/Providers/SeoPanelProvider.php'
        );

        self::assertStringContainsString(ArticleSocialLinkController::class, $provider);
        self::assertStringContainsString("->name('seo.articles.social-links.store')", $provider);
        self::assertStringContainsString("->name('seo.articles.social-links.index')", $provider);
        self::assertStringContainsString('/{article}/social-links', $provider);
    }

    public function test_controller_returns_structured_partial_success_payload(): void
    {
        $source = (string) file_get_contents(
            (new \ReflectionClass(ArticleSocialLinkController::class))->getFileName()
        );

        self::assertStringContainsString("'ok' => true", $source);
        self::assertStringContainsString("'result' =>", $source);
        self::assertStringContainsString('SOURCE_API', $source);
        self::assertStringContainsString('canAccessArticle', $source);
        self::assertStringNotContainsString('ValidationException', $source);
    }
}
