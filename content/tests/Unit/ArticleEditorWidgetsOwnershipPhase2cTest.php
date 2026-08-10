<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorFaqSnapshotService;
use Omnichannel\Addons\Content\Services\ArticleFaqGeneratorService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Phase 2C â€” FAQ/CTA widget ownership + insertion contracts.
 */
final class ArticleEditorWidgetsOwnershipPhase2cTest extends TestCase
{
    public function test_faq_snapshot_service_contract(): void
    {
        $class = new ReflectionClass(ArticleEditorFaqSnapshotService::class);
        self::assertTrue($class->hasMethod('build'));
        self::assertTrue($class->hasMethod('bumpVersion'));
        $source = (string) file_get_contents((string) $class->getFileName());
        self::assertStringContainsString('META_SNAPSHOT_VERSION', $source);
        self::assertStringContainsString('can_generate_ai', $source);
        self::assertStringContainsString('seo_faqs', $source);
    }

    public function test_faq_generator_has_preview_without_auto_body_write(): void
    {
        $class = new ReflectionClass(ArticleFaqGeneratorService::class);
        self::assertTrue($class->hasMethod('generatePreview'));
        self::assertTrue($class->hasMethod('generate'));
        $source = (string) file_get_contents((string) $class->getFileName());
        self::assertStringContainsString("'preview' => true", $source);
        // generatePreview must not call persistArticleBodyHtml
        $previewMethod = $class->getMethod('generatePreview');
        $start = $previewMethod->getStartLine();
        $end = $previewMethod->getEndLine();
        $lines = array_slice(explode("\n", $source), $start - 1, $end - $start + 1);
        $previewBody = implode("\n", $lines);
        self::assertStringNotContainsString('persistArticleBodyHtml', $previewBody);
        self::assertStringNotContainsString('saveFromEditor', $previewBody);
    }

    public function test_faq_and_cta_routes_registered(): void
    {
        $provider = (string) file_get_contents(
            LegacyAddonPath::resolve('Providers/SeoPanelProvider.php'),
        );
        self::assertStringContainsString('faq-snapshot', $provider);
        self::assertStringContainsString('generate-preview', $provider);
        self::assertStringContainsString('DomainCtaQuickTemplatesController', $provider);
        self::assertStringContainsString('ArticleEditorFaqSnapshotController', $provider);
    }

    public function test_frontend_faq_snapshot_and_cta_ownership_wiring(): void
    {
        $faqClient = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleEditorFaqSnapshot.js',
        );
        self::assertStringContainsString('replaceFaqSnapshot', $faqClient);
        self::assertStringContainsString('generateFaqPreview', $faqClient);
        self::assertStringContainsString('applyFaqSnapshot', $faqClient);
        self::assertStringContainsString('rememberFaqSnapshot', $faqClient);

        $faqEditor = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/ArticleFaqEditor.jsx',
        );
        self::assertStringContainsString('replaceFaqSnapshot', $faqEditor);
        self::assertStringContainsString('generateFaqPreview', $faqEditor);
        self::assertStringContainsString('applyAiFaqPreview', $faqEditor);
        self::assertStringContainsString('clearFaqDraft', $faqEditor);
        self::assertStringNotContainsString("new CustomEvent('save-article-faqs'", $faqEditor);
        self::assertStringNotContainsString("new CustomEvent('generate-article-faqs')", $faqEditor);

        $cta = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/CtaContactInsertList.jsx',
        );
        self::assertStringContainsString('/api/seo/domain-cta/quick-templates', $cta);
        self::assertStringContainsString('server canonical', $cta);
        self::assertStringContainsString("effectiveMode = mode === 'value' ? 'value' : 'sentence'", $cta);

        $commands = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/editorCommands/insertionCommands.js',
        );
        self::assertStringContainsString('insertContactValueCommand', $commands);
        self::assertStringContainsString('insertContactCtaCommand', $commands);
        $ctx = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/editorCommands/editorCommandContext.js',
        );
        self::assertStringContainsString('assertWritableCommandContext', $ctx);
        self::assertFileDoesNotExist(
            ProjectRoot::addonsPath().'/content/resources/js/utils/editorInsertionCommands.js',
        );

        $storage = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleEditorStorage.js',
        );
        self::assertStringContainsString('clearFaqDraft', $storage);
    }

    public function test_widgets_ownership_doc_exists(): void
    {
        $path = ProjectRoot::path().'/docs/architecture/ARTICLE_EDITOR_WIDGETS_OWNERSHIP.md';
        self::assertFileExists($path);
        $body = (string) file_get_contents($path);
        self::assertStringContainsString('FAQ', $body);
        self::assertStringContainsString('CTA', $body);
        self::assertStringContainsString('EditorInsertionContext', $body);
    }
}
