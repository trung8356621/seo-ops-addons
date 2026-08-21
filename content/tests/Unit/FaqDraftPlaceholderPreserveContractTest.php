<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

final class FaqDraftPlaceholderPreserveContractTest extends TestCase
{
    public function test_faq_editor_preserves_empty_and_incomplete_drafts_after_snapshot_ack(): void
    {
        $editor = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/ArticleFaqEditor.jsx',
        );
        $util = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/faqDraftPlaceholders.js',
        );

        self::assertStringContainsString('mergeFaqRowsPreservingDrafts', $editor);
        self::assertStringContainsString('isFaqUnpersistedLocal', $editor);
        self::assertStringContainsString('mergeGeneratedFaqsWithExisting', $editor);
        self::assertStringContainsString('faqRowClientKey', $editor);
        self::assertStringContainsString('localNow', $editor);
        self::assertStringContainsString('faqsRef.current = rows', $editor);
        self::assertStringContainsString('export function mergeFaqRowsPreservingDrafts', $util);
        self::assertStringContainsString('export function isFaqDraftPlaceholder', $util);
        self::assertStringContainsString('export function isFaqUnpersistedLocal', $util);
        self::assertStringContainsString('export function mergeGeneratedFaqsWithExisting', $util);
        self::assertStringContainsString('needsFlush', $util);
        // Manual FAQ mid-edit (question filled, answer empty) must not be treated as disposable.
        self::assertStringContainsString('question === \'\' || answer === \'\'', $util);
        // Do not drop incomplete rows in normalizeFaqRows filter.
        self::assertStringNotContainsString(
            'isFaqDraftPlaceholder(row) || String(row.answer',
            $editor,
        );

        $faqAnswer = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/FaqAnswerEditor.jsx',
        );
        self::assertStringContainsString('safeFaqEditorHtml', $faqAnswer);
        self::assertStringContainsString('editor.isDestroyed', $faqAnswer);

        $persistence = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/SeoFaqPersistenceService.php',
        );
        self::assertStringContainsString("if (\$question === '' || \$answer === '')", $persistence);
    }

    public function test_faq_draft_placeholder_selftest_script_covers_manual_cases(): void
    {
        $selftest = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/tests/Unit/faqDraftPlaceholders.selftest.mjs',
        );

        self::assertStringContainsString('keep question-only manual row after ACK', $selftest);
        self::assertStringContainsString('dedupe generated against manual', $selftest);
        self::assertStringContainsString('key #1 stable after delete #2', $selftest);
        self::assertStringContainsString('no duplicate row after autosave ACK', $selftest);
        self::assertStringContainsString('complete raced row kept', $selftest);
    }
}
