<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;

/**
 * Featured set must preserve media identity from picker aliases.
 */
final class ArticleEditorFeaturedIdentityContractTest extends TestCase
{
    public function test_shared_picker_normalize_keeps_identity_aliases(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/resources/js/editor/host/SharedMediaPicker.jsx',
        );
        self::assertStringContainsString('wpAttachmentId', $source);
        self::assertStringContainsString('seoMediaId', $source);
        self::assertStringContainsString('attachment_id', $source);
        self::assertStringContainsString('image?.src', $source);
    }

    public function test_set_featured_api_normalizes_item(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleEditorMediaSnapshot.js',
        );
        self::assertStringContainsString('export function normalizeFeaturedMediaItem', $source);
        self::assertStringContainsString('normalizeFeaturedMediaItem(item)', $source);
        self::assertStringContainsString('wp_attachment_id: normalized.wp_attachment_id', $source);
    }

    public function test_featured_sidebar_uses_normalize_helper(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/resources/js/editor/modules/featured/FeaturedSidebarPanel.jsx',
        );
        self::assertStringContainsString('normalizeFeaturedMediaItem', $source);
        self::assertStringContainsString('await media.setFeatured(item)', $source);
    }

    public function test_mutation_service_accepts_attachment_id_alias(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleEditor/ArticleEditorMediaMutationService.php',
        );
        self::assertStringContainsString('attachment_id', $source);
        self::assertStringContainsString('media_id', $source);
        self::assertStringContainsString('Chá»n áº£nh tá»« tab Local/WordPress', $source);
    }

    public function test_controller_merges_top_level_identity(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/src/Http/Controllers/ArticleEditorMediaSnapshotController.php',
        );
        self::assertStringContainsString('Merge top-level identity', $source);
        self::assertStringContainsString('wp_attachment_id', $source);
    }

    public function test_url_resolver_handles_absolute_storage_paths(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/src/Services/ArticleMediaLocalService.php',
        );
        self::assertStringContainsString('uploads/seo_media/', $source);
        self::assertStringContainsString('Basename fallback', $source);
    }
}
