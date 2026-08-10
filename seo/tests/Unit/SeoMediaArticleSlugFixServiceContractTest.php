<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Media\Services\SeoMediaArticleSlugFixService;
use Omnichannel\Addons\Media\Services\SeoMediaStorageService;
use Omnichannel\Addons\Media\Services\SeoMediaUrlReplacementService;
use Omnichannel\Addons\WordPress\Services\WordPressAttachmentRenameService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Contract tests for Fix slug all rename map shape + URL rewrite helpers.
 * Full disk/DB rename covered by integration; unit here locks API contract frontend depends on.
 */
final class SeoMediaArticleSlugFixServiceContractTest extends TestCase
{
    public function test_url_replacement_updates_html_and_json_block_refs(): void
    {
        $service = new SeoMediaUrlReplacementService();
        $old = '/storage/uploads/seo_media/site-1/old-name.png';
        $new = '/storage/uploads/seo_media/site-1/new-name.png';
        $html = '<p><img src="'.$old.'" data-src="'.$old.'" srcset="'.$old.' 1x"></p>';
        $json = '{"blocks":[{"type":"image","src":"'.$old.'"}]}';

        $nextHtml = $service->replaceInText($html, [$old => $new]);
        $nextJson = $service->replaceInText($json, [$old => $new]);

        self::assertStringContainsString($new, $nextHtml);
        self::assertStringNotContainsString($old, $nextHtml);
        self::assertStringContainsString($new, $nextJson);
        self::assertStringNotContainsString($old, $nextJson);
    }

    public function test_url_replacement_handles_multiple_occurrences(): void
    {
        $service = new SeoMediaUrlReplacementService();
        $old = '/storage/uploads/seo_media/a/old.png';
        $new = '/storage/uploads/seo_media/a/kw-1.png';
        $body = '<img src="'.$old.'"><figure><img src="'.$old.'"></figure>';

        $next = $service->replaceInText($body, [$old => $new]);

        self::assertSame(2, substr_count($next, $new));
        self::assertSame(0, substr_count($next, $old));
    }

    public function test_find_remaining_old_refs_detects_stale_urls(): void
    {
        $service = new SeoMediaUrlReplacementService();
        $old = '/storage/uploads/seo_media/a/old.png';
        $new = '/storage/uploads/seo_media/a/new.png';

        $remaining = $service->findRemainingOldRefs(
            '<img src="'.$old.'">',
            [$old => $new],
        );

        self::assertNotEmpty($remaining);
    }

    public function test_empty_url_map_leaves_content_untouched(): void
    {
        $service = new SeoMediaUrlReplacementService();
        $body = '<p>no images</p>';

        self::assertSame($body, $service->replaceInText($body, []));
        self::assertSame([], $service->findRemainingOldRefs($body, []));
    }

    public function test_fix_slugs_service_exposes_rename_map_keys_in_docblock_contract(): void
    {
        $reflection = new ReflectionClass(SeoMediaArticleSlugFixService::class);
        $method = $reflection->getMethod('fixSlugs');
        $doc = (string) $method->getDocComment();

        // Keep wiring: controller → SeoMediaArticleSlugFixService (single pipeline).
        self::assertStringContainsString('replacements', $doc);
        self::assertTrue($reflection->hasMethod('renameOne'));
        self::assertTrue($reflection->hasMethod('fixSlugs'));

        $renameOne = $reflection->getMethod('renameOne');
        self::assertGreaterThanOrEqual(3, $renameOne->getNumberOfParameters());
        $source = (string) file_get_contents((string) $reflection->getFileName());
        self::assertStringContainsString(
            'rewriteArticleReferences($article, $urlMap, $context)',
            $source,
        );

        $storage = $this->createMock(SeoMediaStorageService::class);
        $urlReplacement = new SeoMediaUrlReplacementService();
        $wpRename = new WordPressAttachmentRenameService();
        $service = new SeoMediaArticleSlugFixService($storage, $urlReplacement, $wpRename);
        self::assertInstanceOf(SeoMediaArticleSlugFixService::class, $service);
    }

    public function test_variant_map_covers_absolute_and_relative_pairs(): void
    {
        $service = new SeoMediaUrlReplacementService();
        $map = $service->buildVariantMap(
            'https://example.test/storage/uploads/seo_media/old.png',
            'https://example.test/storage/uploads/seo_media/new.png',
        );

        self::assertArrayHasKey(
            'https://example.test/storage/uploads/seo_media/old.png',
            $map,
        );
        self::assertSame(
            '/storage/uploads/seo_media/new.png',
            $map['/storage/uploads/seo_media/old.png'] ?? null,
        );
    }
}
