<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticlePendingInternalLinkService;
use Omnichannel\Addons\SearchFoundation\Models\SeoPendingInternalLink;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pending Internal Link create/process retired; HTML helper + model retained.
 */
final class ArticlePendingInternalLinkServiceTest extends TestCase
{
    public function test_replace_placeholder_in_html_swaps_hash_href(): void
    {
        $service = app(ArticlePendingInternalLinkService::class);

        $html = '<p>Đọc thêm <a href="#a1b2c3d4">thời trang bền vững</a> nhé.</p>';
        $next = $service->replacePlaceholderInHtml($html, 'a1b2c3d4', 'https://shop.test/thoi-trang-ben-vung');

        $this->assertStringContainsString('href="https://shop.test/thoi-trang-ben-vung"', $next);
        $this->assertStringNotContainsString('#a1b2c3d4', $next);
    }

    public function test_replace_placeholder_supports_slug_style_hash(): void
    {
        $service = app(ArticlePendingInternalLinkService::class);

        $html = '<p><a href="#tu-khoa">anchor</a></p>';
        $next = $service->replacePlaceholderInHtml($html, 'tu-khoa', 'https://shop.test/tu-khoa');

        $this->assertStringContainsString('href="https://shop.test/tu-khoa"', $next);
        $this->assertStringNotContainsString('#tu-khoa', $next);
    }

    public function test_assign_from_editor_is_retired_failure(): void
    {
        $article = new SeoArticle();
        $result = app(ArticlePendingInternalLinkService::class)->assignFromEditor($article, 'phrase', 1);

        self::assertFalse($result['success'] ?? true);
        self::assertNotSame('', trim((string) ($result['message'] ?? '')));
    }

    public function test_resolve_methods_are_noop(): void
    {
        $service = app(ArticlePendingInternalLinkService::class);
        self::assertSame(0, $service->resolveForKeyword(42));
        self::assertSame(0, $service->resolveForMainArticle(new SeoArticle()));
    }

    public function test_create_path_removed_from_service_source(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ArticlePendingInternalLinkService::class))->getFileName(),
        );
        self::assertStringNotContainsString('function createPending', $src);
        self::assertStringNotContainsString('SeoPendingInternalLink::query()->create', $src);
        self::assertStringNotContainsString('generateUniqueHash', $src);
        self::assertSame(SeoPendingInternalLink::class, ArticlePendingInternalLinkService::pendingModelClass());
    }
}
