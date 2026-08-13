<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;



use Tests\Support\ProjectRoot;
use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Content\Http\Controllers\ArticleEditorLazyPayloadController;
use Omnichannel\Addons\Content\Services\ArticleEditorLinksPayloadService;
use Omnichannel\Addons\Content\Services\ArticleInternalLinkSuggestionService;
use Omnichannel\Addons\Content\Services\ArticleLinkSuggestionContentKeywordFallback;
use Omnichannel\Addons\Content\Services\ArticleLinkSuggestionContentPhraseExtractor;
use Omnichannel\Addons\Seo\Support\LinkSuggestionScoreScale;
use Omnichannel\Addons\Seo\Support\LinkSuggestionStopPhraseFilter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Runtime wiring â€” button Â«Táº¡o gá»£i Ã½ liÃªn káº¿tÂ» pháº£i resolve content Ä‘Ãºng
 * (khÃ´ng chá»‰ articles.body) vÃ  cháº¡y fallback khi primary < target.
 */
final class ArticleLinkSuggestionRuntimeWiringTest extends TestCase
{
    public function test_controller_accepts_posted_content_and_fallback_mode(): void
    {
        $body = $this->methodBody(ArticleEditorLazyPayloadController::class, 'linksSuggestions');

        self::assertStringContainsString('submittedEditorContent', $body);
        self::assertStringContainsString("mode === 'fallback'", $body);
        self::assertStringContainsString('withFallbackOnly', $body);
        self::assertStringContainsString('withSuggestions', $body);
        self::assertStringNotContainsString("article->body ?? ''", $body);
    }

    public function test_payload_service_resolves_scoring_content_not_raw_body(): void
    {
        $body = $this->methodBody(ArticleEditorLinksPayloadService::class, 'resolveSuggestionContent');

        self::assertStringContainsString('resolveScoringContentForArticle', $body);
        self::assertStringContainsString('$submittedContent', $body);
    }

    public function test_stop_phrase_lien_he_shared_filter(): void
    {
        self::assertTrue(LinkSuggestionStopPhraseFilter::isStopPhrase('liÃªn há»‡'));
        self::assertTrue(LinkSuggestionStopPhraseFilter::isStopPhrase('LiÃªn Há»‡'));
        self::assertFalse(LinkSuggestionStopPhraseFilter::isStopPhrase('ngÄƒn chá»‘ng sá»‘c'));
    }

    public function test_score_scale_is_zero_to_one_hundred(): void
    {
        self::assertSame(100, LinkSuggestionScoreScale::MAX);
        self::assertSame(100, LinkSuggestionScoreScale::clamp(150));
        self::assertSame(0, LinkSuggestionScoreScale::clamp(-3));
    }

    public function test_primary_pipeline_filters_stop_phrases(): void
    {
        $body = $this->methodBody(ArticleInternalLinkSuggestionService::class, 'collectCandidates');

        self::assertStringContainsString('LinkSuggestionStopPhraseFilter::isStopPhrase', $body);
        self::assertStringContainsString('shouldRun($primaryValidInternal)', $body);
        self::assertStringNotContainsString('outlineHeadingPhrases', $body);

        $serviceSource = (string) file_get_contents(
            (new ReflectionClass(ArticleInternalLinkSuggestionService::class))->getFileName()
        );
        self::assertStringContainsString('[LINK_FALLBACK_DEBUG]', $serviceSource);
    }

    public function test_fallback_gate_uses_valid_primary_count(): void
    {
        $body = $this->methodBody(ArticleLinkSuggestionContentKeywordFallback::class, 'shouldRun');
        self::assertStringContainsString('$currentInternalCount < $this->targetCount()', $body);

        $collect = $this->methodBody(ArticleInternalLinkSuggestionService::class, 'collectCandidates');
        self::assertStringContainsString('$primaryValidInternal = count($internalSuggestions)', $collect);
        self::assertStringContainsString('shouldRun($primaryValidInternal)', $collect);
    }

    public function test_extractor_pulls_strong_before_heading_from_real_html_shape(): void
    {
        $extractor = new ArticleLinkSuggestionContentPhraseExtractor;
        $html = <<<'HTML'
            <h2>CÃ¡c dÃ²ng balo thá»i trang há»c Ä‘Æ°á»ng</h2>
            <p>Sáº£n pháº©m dÃ¹ng <strong>ngÄƒn chá»‘ng sá»‘c</strong> vÃ  <strong>chá»‘ng tháº¥m nÆ°á»›c</strong>.</p>
            <p>PhÃ¹ há»£p <em>thiáº¿t bá»‹ há»c táº­p</em> vÃ  <mark>mÃ¡y tÃ­nh xÃ¡ch tay</mark>.</p>
            <p>Vui lÃ²ng <a href="/contact">liÃªn há»‡</a> náº¿u cáº§n.</p>
            HTML;

        $phrases = $extractor->extract($html);
        $texts = array_map(static fn (array $row): string => mb_strtolower($row['phrase']), $phrases);

        self::assertNotContains('liÃªn há»‡', $texts);
        self::assertNotContains('cÃ¡c dÃ²ng balo thá»i trang há»c Ä‘Æ°á»ng', $texts);
        self::assertNotSame([], $phrases);
        self::assertContains($phrases[0]['source'], ['strong', 'mark', 'em', 'entity', 'noun_phrase']);
        self::assertTrue(
            in_array('ngÄƒn chá»‘ng sá»‘c', $texts, true)
            || in_array('chá»‘ng tháº¥m nÆ°á»›c', $texts, true)
            || in_array('thiáº¿t bá»‹ há»c táº­p', $texts, true)
            || in_array('mÃ¡y tÃ­nh xÃ¡ch tay', $texts, true),
            'Expected highlight phrases, got: '.implode(' | ', $texts),
        );
    }

    public function test_provider_registers_post_suggestions_route(): void
    {
        $source = (string) file_get_contents(
            LegacyAddonPath::resolve('Providers/SeoPanelProvider.php'),
        );
        self::assertStringContainsString("editor/links/suggestions", $source);
        self::assertStringContainsString('links-suggestions.post', $source);
    }

    public function test_sidebar_posts_editor_html_and_has_unified_suggestion_button(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/ArticleLinksSidebar.jsx',
        );
        self::assertStringContainsString('requestEditorDocumentHtml', $source);
        self::assertStringContainsString("method: 'POST'", $source);
        self::assertStringContainsString("mode: 'fallback'", $source);
        self::assertStringContainsString('suggestionCursorRef', $source);
        self::assertStringContainsString('links_find_more_suggestions', $source);
        self::assertStringContainsString('is-content-suggestion', $source);
        self::assertStringContainsString('findPhraseOccurrencesInBlocks', $source);
        self::assertStringNotContainsString('onGenerateFallbackSuggestions', $source);
        self::assertStringNotContainsString('links_generate_fallback', $source);
    }

    public function test_suggestion_anchor_supports_inline_double_click_edit(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/ArticleLinksSidebar.jsx',
        );
        $i18n = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/i18n.js',
        );
        $css = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/css/article-editor.css',
        );

        self::assertStringContainsString('function keywordRowKey', $source);
        self::assertStringContainsString('${variant}-${target}-kw-${keywordId}', $source);
        self::assertStringContainsString('onDoubleClick', $source);
        self::assertStringContainsString('startAnchorEdit', $source);
        self::assertStringContainsString('commitAnchorEdit', $source);
        self::assertStringContainsString('cancelAnchorEdit', $source);
        self::assertStringContainsString("e.key === 'Enter'", $source);
        self::assertStringContainsString("e.key === 'Escape'", $source);
        self::assertStringContainsString('onUpdateSuggestionAnchor', $source);
        self::assertStringContainsString('updateSuggestionAnchor', $source);
        self::assertStringContainsString('patchSuggestionAnchorInList', $source);
        self::assertStringContainsString('setAnchorEditTick', $source);
        self::assertStringContainsString('wp-article-links-keyword-edit', $source);
        self::assertStringContainsString('is-editable-anchor', $source);
        self::assertStringContainsString('suppressClickRef', $source);
        self::assertStringContainsString('links_suggestion_edit_anchor_hint', $i18n);
        self::assertStringContainsString('Double-click to edit anchor text', $i18n);
        self::assertStringContainsString('Double click để sửa anchor text', $i18n);
        self::assertStringContainsString('.wp-article-links-keyword-edit', $css);
        self::assertStringContainsString('.wp-article-links-keyword.is-suggestion.is-editable-anchor', $css);
    }

    public function test_editor_responds_to_document_html_request(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/hooks/useArticleEditorImageLifecycle.js',
        );
        self::assertStringContainsString('seo-editor-document-html-request', $source);
        self::assertStringContainsString('seo-editor-document-html', $source);
    }

    /**
     * @param  class-string  $class
     */
    private function methodBody(string $class, string $method): string
    {
        $ref = new ReflectionClass($class);
        $m = $ref->getMethod($method);
        $lines = explode("\n", (string) file_get_contents((string) $ref->getFileName()));

        return implode("\n", array_slice(
            $lines,
            $m->getStartLine() - 1,
            $m->getEndLine() - $m->getStartLine() + 1,
        ));
    }
}
