<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ResolvesMovedAddonPaths;

/**
 * Local Laravel Featured must not be classified WP-protected via stale attachment id.
 */
final class ArticleEditorLocalFeaturedNotWpProtectedTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    private function read(string $relative): string
    {
        return $this->readLegacyOrMovedAddonFile($relative);
    }

    public function test_client_classifier_prefers_local_storage_over_stale_wp_id(): void
    {
        $src = $this->read('resources/js/utils/mediaSourceClassification.js');
        self::assertStringContainsString('hasLocalLaravelEvidence', $src);
        self::assertStringContainsString('isLocalSeoMediaSrc', $src);
        self::assertStringContainsString('/storage/uploads/seo_media/', $src);
        self::assertStringContainsString('Local Laravel file evidence wins over bare/stale wp_attachment_id', $src);
        // Explicit source field from snapshot (source, not only source_type).
        self::assertStringContainsString('row?.source', $src);
        // Trusted WP URL still wins.
        self::assertStringContainsString('hasTrustedWordPressUrl', $src);
        self::assertStringContainsString('/wp-content/uploads/', $src);
    }

    public function test_inventory_drops_stale_wp_id_for_non_wordpress_source(): void
    {
        $inventory = $this->read('resources/js/utils/unifiedArticleImagesInventory.js');
        self::assertStringContainsString('effectiveWpAttachmentId', $inventory);
        self::assertStringContainsString("source === 'wordpress' ? wpAttachmentId : null", $inventory);
        self::assertStringContainsString('source_type: raw.source_type ?? raw.sourceType ?? raw.source', $inventory);
    }

    public function test_snapshot_enrich_clears_false_wp_id_for_local_url(): void
    {
        $service = $this->read('Services/ArticleEditor/ArticleEditorMediaSnapshotService.php');
        self::assertStringContainsString('isLocalLaravelMediaUrl', $service);
        self::assertStringContainsString('/storage/uploads/seo_media/', $service);
        self::assertStringContainsString('Never emit that as wp_attachment_id', $service);
        self::assertStringContainsString('$realWp = (int) ($seoMedia->wp_attachment_id ?? 0)', $service);
        // Local URL classified before bare wp id.
        self::assertMatchesRegularExpression(
            '/isLocalLaravelMediaUrl\(\$url\).*return \'local\'/s',
            $service,
        );
    }
}
