<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

/**
 * Featured picker ingest must reuse Image-block media API (upload / paste / URL).
 */
final class ArticleEditorFeaturedPickerIngestContractTest extends TestCase
{
    private function picker(): string
    {
        return (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/resources/js/editor/host/SharedMediaPicker.jsx',
        );
    }

    public function test_picker_reuses_image_flow_upload_paste_and_url(): void
    {
        $picker = $this->picker();

        self::assertStringContainsString('uploadLocalMediaFiles', $picker);
        self::assertStringContainsString('importSeoMediaFromUrl', $picker);
        self::assertStringContainsString('processClipboardImagePaste', $picker);
        self::assertStringContainsString('preferTextPasteInInputs: true', $picker);
        self::assertStringContainsString("window.addEventListener('paste', onPaste)", $picker);
        self::assertStringContainsString("window.removeEventListener('paste', onPaste)", $picker);
        self::assertStringContainsString('data-media-picker-upload="1"', $picker);
        self::assertStringContainsString('data-media-picker-import-url="1"', $picker);
        self::assertStringContainsString('ingestBusyRef', $picker);
        self::assertStringContainsString('fileInputRef', $picker);
        self::assertStringNotContainsString('querySelector', $picker);
        self::assertStringNotContainsString('getElementById', $picker);
        self::assertStringNotContainsString('/api/seo/media/upload', $picker);
    }

    public function test_shared_helpers_keep_single_upload_endpoint(): void
    {
        $upload = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/resources/js/utils/seoLocalMediaUpload.js',
        );
        $api = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/resources/js/utils/seoMediaApi.js',
        );
        $imageBlock = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/resources/js/components/ImageBlockEditor.jsx',
        );

        self::assertStringContainsString('uploadSeoMediaFromFile', $upload);
        self::assertStringContainsString("/api/seo/media/upload", $api);
        self::assertStringContainsString("/api/seo/media/import-url", $api);
        self::assertStringContainsString('preferTextPasteInInputs', $api);
        self::assertStringContainsString('isEditorTypingTarget', $api);
        self::assertStringContainsString('processClipboardImagePaste', $imageBlock);
        self::assertStringContainsString('importSeoMediaFromUrl', $imageBlock);
    }

    public function test_editor_passes_site_id_into_picker(): void
    {
        $host = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/SeoArticleEditor.jsx',
        );
        $i18n = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/i18n.js',
        );

        self::assertStringContainsString('siteId={siteId}', $host);
        self::assertStringContainsString('<SharedMediaPicker', $host);
        self::assertStringContainsString('media_picker_upload:', $i18n);
        self::assertStringContainsString('media_picker_from_url:', $i18n);
        self::assertStringContainsString('Tải ảnh lên', $i18n);
        self::assertStringContainsString('Từ URL', $i18n);
    }

    public function test_media_ai_exposes_title_alias_for_thumbnail_prompt(): void
    {
        $service = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/src/Services/ArticleEditorMediaAiService.php',
        );

        self::assertStringContainsString("'title' => \$postTitle", $service);
        self::assertStringContainsString("'post_title' => \$postTitle", $service);
        self::assertStringContainsString("in_array('title', \$allowedNames, true)", $service);
        self::assertStringContainsString('$variables[\'title\'] ?? $variables[\'post_title\']', $service);
    }
}
