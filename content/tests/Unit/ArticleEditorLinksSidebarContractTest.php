<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Source/contract tests for:
 * 1. Auto-search removal — ArticleLinksSidebar must NOT call loadLinkSuggestions on mount
 * 2. Semantic classification — linkMapsToExtractedArray must include semantic metadata
 * 3. Performance — base() must not invoke the suggestion pipeline
 */
final class ArticleEditorLinksSidebarContractTest extends TestCase
{
    // ── Part B1: Auto-search removal ──

    public function test_sidebar_session_restore_does_not_auto_call_load_suggestions(): void
    {
        $source = $this->sidebarJsxSource();
        $sessionBlock = $this->extractSessionRestoreBlock($source);

        // The session restore block must NOT contain an auto-call to loadLinkSuggestions
        self::assertStringNotContainsString(
            'void loadLinkSuggestions()',
            $sessionBlock,
            'Session restore block must not auto-trigger loadLinkSuggestions',
        );
        self::assertStringNotContainsString(
            'loadLinkSuggestions()',
            $sessionBlock,
            'Session restore block must not call loadLinkSuggestions() in any form',
        );
    }

    public function test_sidebar_retains_session_restoration_branch(): void
    {
        $source = $this->sidebarJsxSource();

        // The session restore useEffect must still load from localStorage
        self::assertStringContainsString('loadInternalLinkSuggestionSession(', $source);
        self::assertStringContainsString('isInternalLinkSuggestionSessionUsable(', $source);
    }

    public function test_sidebar_retains_stale_session_clearing(): void
    {
        $source = $this->sidebarJsxSource();

        // Must still clear stale sessions
        self::assertStringContainsString('clearInternalLinkSuggestionSession(', $source);
    }

    public function test_sidebar_still_has_generate_suggestions_button(): void
    {
        $source = $this->sidebarJsxSource();

        // The Generate Suggestions button callback must exist
        self::assertStringContainsString('onGenerateSuggestions', $source);
        self::assertStringContainsString('links_generate_suggestions', $source);
    }

    public function test_sidebar_load_link_suggestions_function_still_exists(): void
    {
        $source = $this->sidebarJsxSource();

        // loadLinkSuggestions function must still be defined for explicit use
        self::assertStringContainsString('const loadLinkSuggestions', $source);
    }

    public function test_sidebar_auto_start_guard_prevents_double_fire(): void
    {
        $source = $this->sidebarJsxSource();

        // suggestionsAutoStartedRef guard must exist
        self::assertStringContainsString('suggestionsAutoStartedRef.current', $source);
    }

    public function test_base_payload_does_not_call_suggestion_pipeline(): void
    {
        $source = $this->sidebarJsxSource();

        // fetchEditorLinksBase must not call fetchEditorLinksSuggestions
        $baseFunction = $this->extractJsFunction($source, 'fetchEditorLinksBase');
        self::assertStringNotContainsString('fetchEditorLinksSuggestions', $baseFunction);
        self::assertStringNotContainsString('suggestBundle', $baseFunction);
    }

    public function test_fetch_editor_links_base_calls_links_endpoint_not_suggestions(): void
    {
        $source = $this->sidebarJsxSource();
        $baseFunction = $this->extractJsFunction($source, 'fetchEditorLinksBase');

        // Must call /editor/links (base), not /editor/links/suggestions
        self::assertStringContainsString('/editor/links', $baseFunction);
        self::assertStringNotContainsString('/editor/links/suggestions', $baseFunction);
    }

    // ── Part B2: Semantic classification ──

    public function test_link_maps_to_extracted_array_includes_needs_review_in_external(): void
    {
        $body = $this->methodBody(SeoArticle::class, 'linkMapsToExtractedArray');

        self::assertStringContainsString('SeoLinkMapType::NeedsReview', $body);
        self::assertStringContainsString('SeoLinkMapType::ManagedCrossSite', $body);
    }

    public function test_link_maps_to_extracted_array_includes_semantic_risk_field(): void
    {
        $body = $this->methodBody(SeoArticle::class, 'linkMapsToExtractedArray');

        self::assertStringContainsString("'semantic_risk'", $body);
        self::assertStringContainsString('semanticRisk()', $body);
    }

    public function test_link_maps_to_extracted_array_does_not_hardcode_user_facing_labels(): void
    {
        $body = $this->methodBody(SeoArticle::class, 'linkMapsToExtractedArray');

        // User-facing presentation labels must NOT be hardcoded in SeoArticle domain logic
        self::assertStringNotContainsString("'Trusted / Reference'", $body);
        self::assertStringNotContainsString("'Warning'", $body);
        self::assertStringNotContainsString("'Safe'", $body);
        self::assertStringNotContainsString("'Low'", $body);
    }

    public function test_link_maps_to_extracted_array_includes_link_type_field(): void
    {
        $body = $this->methodBody(SeoArticle::class, 'linkMapsToExtractedArray');

        self::assertStringContainsString("'link_type'", $body);
    }

    public function test_semantic_badge_css_exists_for_review_variant(): void
    {
        $css = (string) file_get_contents(
            dirname(__DIR__, 2) . '/resources/css/article-editor.css',
        );

        self::assertStringContainsString('.wp-article-links-semantic-badge--review', $css);
        self::assertStringContainsString('.wp-article-links-semantic-badge--low', $css);
        self::assertStringContainsString('.wp-article-links-semantic-badge--safe', $css);
        self::assertStringContainsString('.wp-article-links-semantic-badge--type', $css);
    }

    public function test_semantic_badge_rendered_in_sidebar_jsx(): void
    {
        $source = $this->sidebarJsxSource();

        self::assertStringContainsString('wp-article-links-semantic-badge', $source);
        self::assertStringContainsString('renderSemanticBadges', $source);
        self::assertStringContainsString('semantic_risk', $source);
    }

    // ── Helpers ──

    private function sidebarJsxSource(): string
    {
        $path = dirname(__DIR__, 2) . '/resources/js/components/ArticleLinksSidebar.jsx';
        self::assertFileExists($path, 'ArticleLinksSidebar.jsx must exist');

        return (string) file_get_contents($path);
    }

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

    private function extractJsFunction(string $source, string $functionName): string
    {
        $pattern = '/(?:async\s+)?function\s+' . preg_quote($functionName, '/') . '\s*\(/';
        if (preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE)) {
            $start = $match[0][1];
            $depth = 0;
            $len = strlen($source);
            for ($i = $start; $i < $len; $i++) {
                if ($source[$i] === '{') {
                    $depth++;
                } elseif ($source[$i] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return substr($source, $start, $i - $start + 1);
                    }
                }
            }
        }

        return '';
    }

    private function extractSessionRestoreBlock(string $source): string
    {
        // Find the session-restore useEffect block (suggestionsAutoStartedRef)
        $marker = 'suggestionsAutoStartedRef.current';
        $pos = strpos($source, $marker);
        if ($pos === false) {
            return '';
        }

        // Extract 3000 chars around the marker to get the full useEffect
        $start = max(0, $pos - 200);
        $end = min(strlen($source), $pos + 3000);
        $block = substr($source, $start, $end - $start);

        // Narrow down: from the clearInternalLinkSuggestionSession call to the next })
        $clearPos = strpos($block, 'clearInternalLinkSuggestionSession');
        if ($clearPos !== false) {
            return substr($block, $clearPos, 500);
        }

        return $block;
    }
}
