<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Tests\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;

final class ProductGalleryGeneratorModalContractTest extends TestCase
{
    public function test_modal_defaults_custom_from_keyword_and_keeps_description_empty(): void
    {
        $editor = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/SeoArticleEditor.jsx',
        );

        self::assertStringContainsString('existingCustom || mainKeyword', $editor);
        self::assertStringContainsString('restoreDraft', $editor);
        self::assertStringContainsString('savedDescription !== keyword', $editor);
        self::assertStringContainsString("setGenerateImageModalPrompt(explicitPrompt || (restoreDraft ? savedDescription : ''))", $editor);
    }

    public function test_completed_gallery_uses_split_outputs_not_source_sprite(): void
    {
        $hook = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/hooks/useArticleEditorImageGeneration.js',
        );

        self::assertStringNotContainsString('Luôn gắn ảnh gốc', $hook);
        self::assertStringContainsString('splitItems', $hook);
        self::assertStringContainsString('saveProductAlbum', $hook);
        self::assertStringContainsString('item.url === sourceUrl', $hook);
        self::assertStringContainsString('item.id === mediaId', $hook);
        self::assertStringNotContainsString('rawItems = [{ id: mediaId, url: trimmedUrl }]', $hook);
    }

    public function test_modal_ui_separates_source_from_gallery_and_hides_raw_prompt(): void
    {
        $modal = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/resources/js/components/GenerateImageModal.jsx',
        );

        self::assertStringContainsString('generate_image_source_heading', $modal);
        self::assertStringContainsString('generate_image_source_helper', $modal);
        self::assertStringContainsString('isNonGalleryArtifactRole', $modal);
        self::assertStringContainsString('setSourceImage', $modal);
        self::assertStringContainsString('setSplitPreviewItems', $modal);
        self::assertStringNotContainsString('generate_image_loai_san_pham_preview_label', $modal);
        self::assertStringNotContainsString('seo-generate-image-modal__prompt-pre', $modal);
        self::assertStringContainsString('hasLoaiSanPham && !submitting', $modal);
    }
}
