<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\ArticleOutlineVocabularySplitExecutor;
use Omnichannel\Addons\AiPrompt\Services\Vocabulary\InternalVocabularyAssembler;
use Omnichannel\Addons\AiPrompt\Services\Vocabulary\VocabularyFinalMerger;
use Omnichannel\Addons\AiPrompt\Services\Vocabulary\VocabularySufficiencyGate;
use Omnichannel\Addons\AiPrompt\Support\Vocabulary\VocabularyMarkdownCodec;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class VocabularyInternalExternalContractTest extends TestCase
{
    public function test_executor_wires_internal_before_external_vocabulary_hook(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ArticleOutlineVocabularySplitExecutor::class))->getFileName(),
        );

        self::assertStringContainsString('InternalVocabularyAssembler', $src);
        self::assertStringContainsString('VocabularySufficiencyGate', $src);
        self::assertStringContainsString('VocabularyFinalMerger', $src);
        self::assertStringContainsString('VocabularyMarkdownCodec', $src);
        self::assertStringContainsString('internal_vocabulary', $src);
        self::assertStringContainsString('assemble(', $src);
        self::assertStringContainsString('isSufficient(', $src);
    }

    public function test_final_merger_prefers_internal_and_gap_fills(): void
    {
        $merger = new VocabularyFinalMerger;
        $merged = $merger->merge(
            ['Semantic keywords' => ['alpha keyword', 'beta keyword']],
            ['Semantic keywords' => ['beta keyword', 'gamma keyword']],
        );

        self::assertSame(['alpha keyword', 'beta keyword', 'gamma keyword'], $merged['Semantic keywords']);
    }

    public function test_codec_roundtrip_uses_markdown_groups(): void
    {
        $codec = new VocabularyMarkdownCodec;
        $md = "### Synonyms\n- a\n- b\n\n### Holonymy\n- c";
        $groups = $codec->parse($md);
        self::assertSame(['a', 'b'], $groups['Synonyms']);
        self::assertStringContainsString('### Synonyms', $codec->render($groups));
    }

    public function test_sufficiency_gate_skips_hard_ratio(): void
    {
        $gate = new VocabularySufficiencyGate;
        self::assertFalse($gate->isSufficient([]));

        $groups = [
            'Long-tail keywords' => ['a one', 'b two'],
            'Semantic keywords' => ['c three', 'd four'],
            'Related topics' => ['e five', 'f six'],
            'Holonymy' => ['g seven', 'h eight'],
            'Synonyms' => ['i nine', 'j ten'],
        ];
        foreach (range(1, 12) as $i) {
            $groups['Salient keywords'][] = "filler term {$i} with length";
        }

        self::assertTrue($gate->isSufficient($groups));
    }

    public function test_internal_assembler_class_exists_for_reuse(): void
    {
        self::assertTrue(class_exists(InternalVocabularyAssembler::class));
    }
}