<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ResolvesMovedAddonPaths;

/**
 * Unified Images inventory + health ownership (Featured/Gallery/content).
 *
 * Behavioral identity/dedupe assertions are mirrored from
 * resources/js/utils/unifiedArticleImagesInventory.js (canonical JS).
 */
final class ArticleEditorUnifiedImagesInventoryHealthTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    private function addonPath(string $relative): string
    {
        return $this->resolveLegacyOrMovedAddonPath($relative);
    }

    private function read(string $relative): string
    {
        $path = $this->addonPath($relative);
        self::assertFileExists($path);
        $body = file_get_contents($path);
        self::assertIsString($body);

        return $body;
    }

    /**
     * Minimal PHP mirror of JS identity priority for contract cases 8–10.
     *
     * @param  array<string, mixed>  $row
     */
    private function identityKey(array $row): string
    {
        $seoId = max(0, (int) ($row['media_id'] ?? $row['seoMediaId'] ?? $row['seo_media_id'] ?? 0));
        if ($seoId > 0) {
            return 'seo:'.$seoId;
        }

        $wpId = max(0, (int) ($row['wp_attachment_id'] ?? $row['wpAttachmentId'] ?? 0));
        if ($wpId > 0) {
            return 'wp:'.$wpId;
        }

        $url = strtolower((string) preg_replace('/[?#].*$/', '', (string) ($row['canonical_url'] ?? $row['url'] ?? $row['src'] ?? '')));
        if ($url !== '') {
            return 'url:'.$url;
        }

        $path = strtolower((string) ($row['local_path'] ?? $row['localSrc'] ?? ''));
        if ($path !== '') {
            return 'path:'.$path;
        }

        $filename = strtolower(trim((string) ($row['filename'] ?? $row['slug'] ?? '')));

        return $filename !== '' ? 'fp:'.$filename : '';
    }

    /**
     * @param  list<array<string, mixed>>  $seeds
     * @return list<array{key: string, roles: array{content: bool, featured: bool, gallery: bool}}>
     */
    private function mergeSeeds(array $seeds): array
    {
        $map = [];
        foreach ($seeds as $seed) {
            $key = $this->identityKey($seed);
            self::assertNotSame('', $key, 'seed must resolve identity');
            if (! isset($map[$key])) {
                $map[$key] = [
                    'key' => $key,
                    'roles' => [
                        'content' => false,
                        'featured' => false,
                        'gallery' => false,
                    ],
                    'filename' => (string) ($seed['filename'] ?? ''),
                ];
            }
            foreach (['content', 'featured', 'gallery'] as $role) {
                if (! empty($seed['role_flags'][$role])) {
                    $map[$key]['roles'][$role] = true;
                }
            }
        }

        return array_values($map);
    }

    public function test_inventory_module_exports_and_dedupe_helpers(): void
    {
        $src = $this->read('resources/js/utils/unifiedArticleImagesInventory.js');
        self::assertStringContainsString('export function buildUnifiedArticleImagesInventory', $src);
        self::assertStringContainsString('export function unifiedImageIdentityKey', $src);
        self::assertStringContainsString('export function unifiedInventoryToImageRows', $src);
        self::assertStringContainsString('export function unifiedInventorySlugFixCandidates', $src);
        self::assertStringContainsString('export function countContentImageOccurrencesFromInventory', $src);
        self::assertStringContainsString('role_flags', $src);
        self::assertStringContainsString('requires_slug_fix', $src);
        self::assertStringContainsString('missing_alt', $src);
        self::assertStringContainsString('Never dedupe by basename alone', $src);
        self::assertStringContainsString('Drop cache-busting', $src);
    }

    public function test_body_featured_gallery_appear_and_dedupe(): void
    {
        // 1–7, 8–10
        $merged = $this->mergeSeeds([
            ['seo_media_id' => 11, 'src' => '/storage/a.jpg', 'role_flags' => ['content' => true]],
            ['seo_media_id' => 22, 'src' => '/storage/feat.jpg', 'role_flags' => ['featured' => true]],
            ['seo_media_id' => 33, 'src' => '/storage/gal.jpg', 'role_flags' => ['gallery' => true]],
            ['seo_media_id' => 11, 'src' => '/storage/a.jpg?v=2', 'role_flags' => ['featured' => true]],
            ['seo_media_id' => 11, 'src' => '/storage/a.jpg', 'role_flags' => ['gallery' => true]],
            ['seo_media_id' => 44, 'src' => '/storage/dir1/same.jpg', 'filename' => 'same.jpg', 'role_flags' => ['content' => true]],
            ['seo_media_id' => 55, 'src' => '/storage/dir2/same.jpg', 'filename' => 'same.jpg', 'role_flags' => ['content' => true]],
            ['src' => 'https://cdn.test/x.jpg?cache=1', 'canonical_url' => 'https://cdn.test/x.jpg', 'role_flags' => ['content' => true]],
            ['src' => 'https://cdn.test/x.jpg?cache=2', 'canonical_url' => 'https://cdn.test/x.jpg', 'role_flags' => ['gallery' => true]],
        ]);

        self::assertCount(6, $merged);
        $byKey = [];
        foreach ($merged as $row) {
            $byKey[$row['key']] = $row['roles'];
        }
        self::assertTrue($byKey['seo:11']['content']);
        self::assertTrue($byKey['seo:11']['featured']);
        self::assertTrue($byKey['seo:11']['gallery']);
        self::assertTrue($byKey['seo:22']['featured']);
        self::assertFalse($byKey['seo:22']['content']);
        self::assertTrue($byKey['seo:33']['gallery']);
        self::assertArrayHasKey('seo:44', $byKey);
        self::assertArrayHasKey('seo:55', $byKey);
        // Same basename must stay two inventory keys (role arrays may look identical).
        $sameBasenameKeys = [];
        foreach ($merged as $row) {
            if (($row['filename'] ?? '') === 'same.jpg') {
                $sameBasenameKeys[] = $row['key'];
            }
        }
        self::assertCount(2, $sameBasenameKeys);
        self::assertNotSame($sameBasenameKeys[0], $sameBasenameKeys[1]);
    }

    public function test_editor_wires_unified_inventory_into_panel_health_and_fix_slug(): void
    {
        $editor = $this->read('resources/js/components/SeoArticleEditor.jsx');
        $tab = $this->read('resources/js/components/ArticleImagesTab.jsx');
        $panel = $this->read('resources/js/editor/modules/media/ImagesSidebarPanel.jsx');

        self::assertStringContainsString('buildUnifiedArticleImagesInventory', $editor);
        self::assertStringContainsString('unifiedInventoryToImageRows', $editor);
        self::assertStringContainsString('unifiedInventorySlugFixCandidates', $editor);
        self::assertStringContainsString('extraImages: unifiedImageRows', $editor);
        self::assertStringContainsString('useUnifiedInventory: true', $editor);
        self::assertStringContainsString('rows: imageRows', $editor);
        self::assertStringContainsString('unifiedImageRows', $editor);
        // Ratio stays content-only.
        self::assertStringContainsString('valid_content_image_count', $editor);
        self::assertStringContainsString('contentImages.valid_content_image_count', $editor);
        self::assertStringContainsString('never unified inventory total', $editor);

        self::assertStringContainsString('useUnifiedInventory', $tab);
        self::assertStringContainsString('role_flags', $tab);
        self::assertStringContainsString('seo-article-images-role-badge', $tab);
        self::assertStringContainsString('useUnifiedInventory', $panel);
        self::assertStringContainsString('featuredImage={images.featuredImage}', $panel);
    }

    public function test_slug_alt_ratio_ownership_and_featured_cleanup(): void
    {
        $health = $this->read('resources/js/utils/assistantWidgetHealth.js');
        $imagesFn = $this->sliceBetween($health, 'export function buildImagesWidgetHealth({', 'export function buildSeoWidgetHealth({');
        $featuredFn = $this->sliceBetween($health, 'export function buildFeaturedWidgetHealth({', 'export function buildGalleryWidgetHealth({');
        $analyzeFn = $this->sliceBetween($health, 'export function analyzeImageRowsHealth(rows, keyword = \'\') {', 'export function buildImagesWidgetHealth({');

        self::assertStringContainsString("code: 'image_slug_unresolved'", $analyzeFn);
        self::assertStringContainsString("severity: 'error'", $analyzeFn);
        self::assertStringContainsString("code: 'image_alt_missing'", $analyzeFn);
        self::assertStringContainsString("severity: 'warning'", $analyzeFn);
        self::assertStringContainsString("code: 'image_ratio_low'", $imagesFn);
        self::assertStringContainsString("severity: 'info'", $imagesFn);
        self::assertStringContainsString("status = 'error'", $imagesFn);
        self::assertStringContainsString("status = 'warning'", $imagesFn);
        self::assertStringContainsString("status = 'info'", $imagesFn);

        self::assertStringNotContainsString('image_ratio_low', $featuredFn);
        self::assertStringNotContainsString('featured_alt_missing', $featuredFn);
        self::assertStringNotContainsString('featured_slug_not_fixed', $featuredFn);
        self::assertStringContainsString('featured_missing', $featuredFn);
        self::assertStringContainsString('Images unified inventory', $featuredFn);

        $policy = $this->read('Services/ArticleEditor/ArticleEditorAnalysisPolicyService.php');
        self::assertStringContainsString("'image_ratio_low' =>", $policy);
        self::assertStringContainsString("'widget' => 'images'", $policy);
        self::assertStringContainsString('image_ratio_low', $policy);
        self::assertStringContainsString("'widget' => 'featured'", $policy);
        // Featured registry entry is featured_missing — not image_ratio_low.
        self::assertMatchesRegularExpression(
            "/'image_ratio_low'\\s*=>\\s*\\[[^\\]]*?'widget'\\s*=>\\s*'images'/s",
            $policy,
        );
    }

    public function test_ratio_copy_and_chip_count_contracts(): void
    {
        $metrics = $this->read('resources/js/utils/seoReasonMetrics.js');
        self::assertStringContainsString('Thiếu khoảng :missing ảnh trong nội dung.', $metrics);
        self::assertStringContainsString('Bài viết nên bổ sung thêm ảnh', $metrics);
        self::assertStringContainsString('Bài viết có :words từ và :current ảnh nội dung; đề xuất khoảng :recommended ảnh.', $metrics);

        $editor = $this->read('resources/js/components/SeoArticleEditor.jsx');
        self::assertStringContainsString('unique inventory assets', $editor);
        self::assertStringContainsString('imageTabCount = unifiedImageRows.length', $editor);

        $inventory = $this->read('resources/js/utils/unifiedArticleImagesInventory.js');
        self::assertStringContainsString('countContentImageOccurrencesFromInventory', $inventory);
        self::assertStringContainsString('role_flags?.content', $inventory);
        self::assertStringContainsString('isWordPressProtectedMedia', $inventory);
        self::assertStringContainsString('isBulkSlugRenameSafeMedia', $inventory);
    }

    public function test_snapshot_refresh_and_readonly_contracts(): void
    {
        $editor = $this->read('resources/js/components/SeoArticleEditor.jsx');
        self::assertStringContainsString('subscribeMediaSnapshot', $editor);
        self::assertStringContainsString('setMediaHealthTick', $editor);
        self::assertStringContainsString('seo-assistant-widget-health-refresh', $editor);
        self::assertStringContainsString('sessionReadOnly', $editor);
        self::assertStringContainsString('canMutateEditor', $editor);
        self::assertStringContainsString('quickFixSlugAllBusy', $editor);
    }

    public function test_media_module_images_provider_owns_ratio_not_featured_module(): void
    {
        $media = $this->read('resources/js/editor/modules/media/index.js');
        self::assertStringContainsString("widgetId: 'images'", $media);
        self::assertStringContainsString('buildImagesWidgetHealth', $media);
        self::assertStringNotContainsString('image_ratio_low', $media);
    }

    public function test_legacy_body_only_merge_skipped_when_unified_inventory_flag_on(): void
    {
        $tab = $this->read('resources/js/components/ArticleImagesTab.jsx');
        $from = strpos($tab, 'if (useUnifiedInventory) {');
        self::assertNotFalse($from);
        $slice = substr($tab, $from, 900);
        self::assertStringContainsString('extraImages', $slice);
        self::assertStringContainsString('assignInArticleQuickFixIndices', $slice);
        self::assertStringNotContainsString('collectImagesFromBlocks', $slice);
        self::assertStringContainsString('seo-article-images-role-badge', $tab);

        $editor = $this->read('resources/js/components/SeoArticleEditor.jsx');
        self::assertStringContainsString('useUnifiedInventory: true', $editor);
        self::assertStringContainsString('extraImages: unifiedImageRows', $editor);
        // Health consumes same unified rows — not a separate body-only catalog.
        self::assertStringContainsString('const imageRows = unifiedImageRows', $editor);
        self::assertStringContainsString('rows: imageRows', $editor);
    }

    public function test_featured_health_stays_clean_of_images_issues(): void
    {
        $health = $this->read('resources/js/utils/assistantWidgetHealth.js');
        $featuredFn = $this->sliceBetween($health, 'export function buildFeaturedWidgetHealth({', 'export function buildGalleryWidgetHealth({');
        self::assertStringContainsString('featured_missing', $featuredFn);
        self::assertStringNotContainsString('image_ratio_low', $featuredFn);
        self::assertStringNotContainsString('image_slug_unresolved', $featuredFn);
        self::assertStringNotContainsString('image_alt_missing', $featuredFn);
        self::assertStringNotContainsString('featured_alt_missing', $featuredFn);
        self::assertStringNotContainsString('featured_slug_not_fixed', $featuredFn);
    }

    private function sliceBetween(string $source, string $start, string $end): string
    {
        $from = strpos($source, $start);
        self::assertNotFalse($from, 'missing start marker');
        $to = strpos($source, $end, $from + strlen($start));
        self::assertNotFalse($to, 'missing end marker');

        return substr($source, $from, $to - $from);
    }
}
