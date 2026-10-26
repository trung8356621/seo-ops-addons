<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

final class ArticleEditorLegacyFaqOwnershipTest extends TestCase
{
    public function test_article_editor_save_does_not_call_php_faq_body_extraction(): void
    {
        $persist = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleEditorPersistService.php',
        );
        $page = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/EditArticle.php',
        );

        $this->assertStringNotContainsString('extractFromBodyWhenMissing', $persist);
        $this->assertStringNotContainsString('extractFromBodyWhenMissing', $page);
    }

    public function test_browser_legacy_faq_flow_is_bounded_and_shared(): void
    {
        $utility = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/legacyFaqDetection.js',
        );
        $transfer = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/legacyFaqTransfer.js',
        );
        $editor = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/SeoArticleEditor.jsx',
        );
        $bridge = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/hooks/useArticleEditorExternalEventsBridge.js',
        );

        $this->assertStringContainsString('.slice(-3)', $utility);
        $this->assertStringContainsString('detectFaqPairsFromSection', $utility);
        $this->assertStringContainsString("extractLegacyFaqSection(section, 'manual')", $editor);
        $this->assertStringContainsString("extractLegacyFaqSection(candidate, 'auto')", $editor);
        $this->assertStringContainsString('restoreLegacyFaqSection', $editor);
        $this->assertStringContainsString('commitLegacyFaqAndReview', $editor);
        $this->assertStringContainsString('commitLegacyFaqToCanonical', $editor);
        $this->assertStringContainsString('resolveFaqsForLegacyCommit', $editor);
        $this->assertStringContainsString('extractedFaqs', $editor);
        $this->assertStringContainsString('beginLegacyFaqCommit', $editor);
        $this->assertStringContainsString('seedFaqEditorWhenReady', $editor);
        $this->assertStringContainsString('revealFaqShortcodeAfterTransfer', $editor);
        $this->assertStringContainsString('legacyFaqTransferring', $editor);
        $this->assertStringContainsString('Đang chuyển FAQ...', $editor);
        $this->assertStringContainsString('skipNextAutosave.current = true', $editor);
        $this->assertStringContainsString('replaceFaqSnapshot', $transfer);
        $this->assertStringContainsString('resolveFaqsForLegacyCommit', $transfer);
        $this->assertStringContainsString('seo-faq-shortcode-block', $transfer);
        $this->assertStringContainsString('shouldIgnoreEmptyFaqEditorSync', $transfer);
        $this->assertStringContainsString('shouldIgnoreEmptyFaqEditorSync', $bridge);
        $this->assertStringContainsString('resolvePendingFaqsForContentSave', $bridge);
    }

    public function test_legacy_faq_transfer_selftest_covers_behavior_cases(): void
    {
        $selftest = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/tests/Unit/legacyFaqTransfer.selftest.mjs',
        );

        $this->assertStringContainsString('pending store owns transfer', $selftest);
        $this->assertStringContainsString('detect 6 Q/A', $selftest);
        $this->assertStringContainsString('second click blocked', $selftest);
        $this->assertStringContainsString('legacy_faq_import_failed', $selftest);
        $this->assertStringContainsString('resolveFaqsForLegacyCommit', $selftest);
    }

    public function test_legacy_faq_transfer_selftest_passes(): void
    {
        $script = ProjectRoot::addonsPath().'/content/tests/Unit/legacyFaqTransfer.selftest.mjs';
        $cmd = 'node '.escapeshellarg($script);
        exec($cmd, $output, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertStringContainsString('legacyFaqTransfer.selftest: ok', implode("\n", $output));
    }
}
