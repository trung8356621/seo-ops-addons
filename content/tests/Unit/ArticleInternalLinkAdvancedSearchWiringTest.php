<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Http\Controllers\ArticleEditorLazyPayloadController;
use Omnichannel\Addons\Content\Services\ArticleEditorLinksPayloadService;
use Omnichannel\Addons\Content\Services\ArticleInternalLinkPipeline;
use Omnichannel\Addons\Content\Services\ArticleInternalLinkSuggestionService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ProjectRoot;

final class ArticleInternalLinkAdvancedSearchWiringTest extends TestCase
{
    public function test_controller_accepts_advanced_mode(): void
    {
        $body = $this->methodBody(ArticleEditorLazyPayloadController::class, 'linksSuggestions');

        self::assertStringContainsString("mode === 'advanced' || \$mode === 'fallback'", $body);
        self::assertStringContainsString('withFallbackOnly', $body);
        self::assertStringNotContainsString('withAdvancedBatch', $body);
    }

    public function test_payload_service_exposes_cursor_and_failed_keys(): void
    {
        $body = $this->methodBody(ArticleEditorLinksPayloadService::class, 'withAdvancedBatch');

        self::assertStringContainsString('suggestAdvancedBatch', $body);
        self::assertStringContainsString('suggestion_cursor', $body);
        self::assertStringContainsString('suggestions_exhausted', $body);
        self::assertStringContainsString('failed_candidate_keys', $body);
    }

    public function test_suggestion_service_respects_display_cap(): void
    {
        $body = $this->methodBody(ArticleInternalLinkSuggestionService::class, 'suggestAdvancedBatch');

        self::assertStringContainsString('max_display_internal', $body);
        self::assertStringContainsString('max_internal_links', $body);
        self::assertStringContainsString('remainingSlots', $body);
        self::assertStringContainsString('collectAdvancedBatch', $body);
        self::assertStringContainsString('min(5', $body);
        self::assertStringContainsString('display_cap_reached', $body);
        self::assertStringContainsString('cursor_preserved', $body);
    }

    public function test_pipeline_advanced_batch_has_stage_resume_cursor(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ArticleInternalLinkPipeline::class))->getFileName()
        );

        self::assertStringContainsString('function collectAdvancedBatch', $src);
        self::assertStringContainsString('ADVANCED_STAGE_ORDER', $src);
        self::assertStringContainsString('advancedCandidateKey', $src);
        self::assertStringContainsString('content_deep', $src);
        self::assertStringContainsString('advanceContentDeepStage', $src);
        self::assertStringContainsString('failed_keys', $src);
    }

    public function test_suggestion_service_uses_usable_count_not_raw_catalog(): void
    {
        $body = $this->methodBody(ArticleInternalLinkSuggestionService::class, 'suggestAdvancedBatch');

        self::assertStringContainsString('countUsableExistingSuggestions', $body);
        self::assertStringContainsString('usable_count', $body);
        self::assertStringContainsString('remainingSlots', $body);
    }

    public function test_session_storage_version_two_invalidates_stale(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleInternalLinkSuggestionSessionStorage.js'
        );

        self::assertStringContainsString('INTERNAL_LINK_SUGGESTION_SESSION_VERSION = 4', $source);
        self::assertStringContainsString('resolveSuggestionSessionCache', $source);
        self::assertStringContainsString('version < INTERNAL_LINK_SUGGESTION_SESSION_VERSION', $source);
    }

    public function test_sidebar_restores_session_and_skips_auto_warm(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/ArticleLinksSidebar.jsx'
        );

        self::assertStringContainsString('loadInternalLinkSuggestionSession', $source);
        self::assertStringContainsString('isInternalLinkSuggestionSessionUsable', $source);
        self::assertStringContainsString('saveInternalLinkSuggestionSession', $source);
        self::assertStringNotContainsString("mode: 'advanced'", $source);
        self::assertStringNotContainsString('links_advanced_search', $source);
        self::assertStringNotContainsString('advancedSearchEnabled', $source);
        self::assertStringContainsString('suggestionRequestSeqRef', $source);
        self::assertStringContainsString('clearInternalLinkSuggestionSession', $source);
        self::assertStringContainsString("mode: 'fallback'", $source);
        $usableAt = strpos($source, 'isInternalLinkSuggestionSessionUsable(session');
        self::assertNotFalse($usableAt);
        self::assertNotFalse(strpos($source, 'return;', (int) $usableAt));
        self::assertStringContainsString('onGenerateSuggestions={loadLinkSuggestions}', $source);
    }

    public function test_sidebar_advanced_mode_reads_sync_ref_not_stale_state(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/ArticleLinksSidebar.jsx'
        );

        self::assertStringNotContainsString('advancedSearchEnabledRef', $source);
        self::assertStringNotContainsString("mode: 'advanced'", $source);
        self::assertStringContainsString("mode: 'fallback'", $source);
        self::assertStringContainsString(
            "suggestionCursorRef.current.phase === 'exhausted'",
            $source
        );
        self::assertStringContainsString('options.shiftKey === true', $source);
        self::assertStringContainsString('suggestionEngines', $source);
    }

    public function test_session_storage_helper_scoped_by_site_and_article(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/articleInternalLinkSuggestionSessionStorage.js'
        );

        self::assertStringContainsString('seo_article_internal_link_suggestion_session_', $source);
        self::assertStringContainsString('contentFingerprint', $source);
        self::assertStringContainsString('failedKeys', $source);
        self::assertStringContainsString('advancedCursor', $source);
        self::assertStringContainsString('isInternalLinkSuggestionSessionUsable', $source);
    }

    /**
     * @param  class-string  $class
     */
    private function methodBody(string $class, string $method): string
    {
        $ref = new ReflectionClass($class);
        $m = $ref->getMethod($method);
        $file = (string) $m->getFileName();
        $lines = file($file);
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $m->getStartLine() - 1,
            $m->getEndLine() - $m->getStartLine() + 1
        ));
    }
}
