<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ResolvesMovedAddonPaths;

/**
 * Owning-session Fix Slug + Images slug/ALT/ratio health.
 */
final class ArticleEditorImagesHealthAndSlugSessionTest extends TestCase
{
    use ResolvesMovedAddonPaths;

    private function readAddon(string $relative): string
    {
        return $this->readLegacyOrMovedAddonFile($relative);
    }

    public function test_fix_slug_sends_editor_session_and_allows_owning_rewrite(): void
    {
        $api = $this->readAddon('resources/js/utils/seoMediaApi.js');
        $controller = $this->readAddon('Http/Controllers/ArticleEditorOperationController.php');
        $sessions = $this->readAddon('Services/ArticleEditor/ArticleEditorSessionService.php');
        $rewrite = $this->readAddon('Services/SeoMediaUrlReplacementService.php');
        $fix = $this->readAddon('Services/SeoMediaArticleSlugFixService.php');

        self::assertStringContainsString('editor_session_id', $api);
        self::assertStringContainsString('X-Editor-Session-Id', $api);
        self::assertStringContainsString('assertOwningActiveSessionForMediaMutation', $controller);
        self::assertStringContainsString('assertOwningActiveSessionForMediaMutation', $sessions);
        self::assertStringContainsString('assertBodyRewriteAllowed', $sessions);
        self::assertStringContainsString('editor_session_id', $rewrite);
        self::assertStringContainsString('rewriteArticleReferences($article, $urlMap, $context)', $fix);
        self::assertStringContainsString("'document_version'", $fix);
        self::assertStringContainsString("'content_hash'", $fix);
        self::assertStringContainsString('storage_adopt', $fix);
        self::assertStringContainsString('isLocalMediaRequest', $fix);
        self::assertStringContainsString('stale wp_attachment_id', $fix);

        $editor = $this->readAddon('resources/js/components/SeoArticleEditor.jsx');
        self::assertStringContainsString('syncVersionAfterSlugFix', $editor);
        self::assertStringContainsString('setDocumentVersion', $editor);
        self::assertStringContainsString('skipLocalQueueRecovery', $editor);
    }

    public function test_local_slug_unresolved_is_error_alt_is_warning(): void
    {
        $health = $this->readAddon('resources/js/utils/assistantWidgetHealth.js');

        self::assertStringContainsString("code: 'image_slug_unresolved'", $health);
        self::assertStringContainsString("severity: 'error'", $health);
        self::assertStringContainsString('requires_slug_fix: true', $health);
        self::assertStringContainsString("code: 'image_alt_missing'", $health);
        self::assertStringContainsString('Ảnh chưa có ALT.', $health);
        self::assertStringContainsString('Ảnh nội bộ chưa được chuẩn hóa slug.', $health);
        self::assertStringNotContainsString("code: 'local_slug_placeholder'", $health);
    }

    public function test_image_ratio_low_restored_with_missing_count(): void
    {
        $health = $this->readAddon('resources/js/utils/assistantWidgetHealth.js');
        $editor = $this->readAddon('resources/js/components/SeoArticleEditor.jsx');
        $metrics = $this->readAddon('resources/js/utils/seoReasonMetrics.js');

        self::assertStringContainsString("code: 'image_ratio_low'", $health);
        self::assertStringContainsString('missingRecommended', $health);
        self::assertStringContainsString('Math.ceil(wordCount / wordsPerImage)', $editor);
        self::assertStringContainsString('missing_image_count', $editor);
        self::assertStringContainsString('TARGET_WORDS_PER_IMAGE = 200', $metrics);
        self::assertStringContainsString('image_ratio_low:', $metrics);
    }

    public function test_https_alone_not_wordpress_for_slug_eligibility(): void
    {
        $source = $this->readAddon('resources/js/utils/mediaSourceClassification.js');

        self::assertStringNotContainsString('/^https?:', $source);
        self::assertStringContainsString('isBulkSlugRenameSafeMedia', $source);
    }
}
