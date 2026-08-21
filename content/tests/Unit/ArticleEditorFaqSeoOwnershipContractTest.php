<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

final class ArticleEditorFaqSeoOwnershipContractTest extends TestCase
{
    public function test_local_seo_owns_draft_and_rejects_non_live_overwrite(): void
    {
        $hook = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/hooks/useArticleEditorSeoAnalysis.js',
        );

        self::assertStringContainsString('source !== \'live\'', $hook);
        self::assertStringContainsString('react_immediate', $hook);
        self::assertStringContainsString('faqsCanonicalKnownRef', $hook);
        self::assertStringContainsString('return null', $hook);
        self::assertStringNotContainsString('runPhpSeoPreview', $hook);
        self::assertStringNotContainsString('previewSeoScoreViaApi', $hook);
        self::assertStringContainsString('applySeoAnalysisResult(result, \'live\')', $hook);
        self::assertStringContainsString('markSeoStale', $hook);
        self::assertStringContainsString('}, 450)', $hook);
    }

    public function test_faq_rows_changed_marks_canonical_and_seo_stale(): void
    {
        $bridge = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/hooks/useArticleEditorExternalEventsBridge.js',
        );

        self::assertStringContainsString('article-faq-rows-changed', $bridge);
        self::assertStringContainsString('faqsCanonicalKnownRef.current = true', $bridge);
        self::assertStringContainsString('markSeoStale()', $bridge);
    }

    public function test_seo_analyzer_known_empty_vs_unknown_contract(): void
    {
        $analyzer = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo/resources/js/utils/seoAnalyzer.js',
        );

        self::assertStringContainsString('export function resolveFaqsForScoring', $analyzer);
        self::assertStringContainsString('Array.isArray(faqs)', $analyzer);
        self::assertStringContainsString('faqs = undefined', $analyzer);
        self::assertStringContainsString('canonical/known FAQ owner state', $analyzer);
        self::assertFileExists(
            ProjectRoot::addonsPath().'/seo/tests/Unit/seoAnalyzer.faqScoring.selftest.mjs',
        );
    }
}
