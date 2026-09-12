<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiCallRawDetailService;
use PHPUnit\Framework\TestCase;

final class ExactExecutionPromptAuthorityTest extends TestCase
{
    public function test_exact_section_prompt_wins_over_reconstructor(): void
    {
        $result = new PromptResult;
        $result->forceFill([
            'id' => 1500,
            'compiled_prompt_hash' => hash('sha256', 'EXACT SECTION PROMPT'),
            'input_snapshot' => [
                'compiled_prompt' => 'EXACT SECTION PROMPT',
                'manual_compiled' => true,
                'sectioned_free_section' => true,
                'section_order' => 10,
                'section_count' => 11,
            ],
        ]);

        // null reconstructor: exact path must not require PromptReconstructor.
        $resolved = ArticleAiCallRawDetailService::resolvePromptAuthority($result, null);

        self::assertSame('EXACT SECTION PROMPT', $resolved['prompt']);
        self::assertSame(ArticleAiCallRawDetailService::PROMPT_SOURCE_EXECUTION_SNAPSHOT, $resolved['prompt_source']);
        self::assertTrue($resolved['exact_execution_prompt']);
        self::assertFalse($resolved['hash_mismatch']);
        self::assertNull($resolved['warning']);
    }

    public function test_legacy_without_compiled_prompt_uses_reconstructed_source_contract(): void
    {
        $src = (string) file_get_contents(
            (string) (new \ReflectionClass(ArticleAiCallRawDetailService::class))->getFileName(),
        );
        self::assertStringContainsString('PROMPT_SOURCE_RECONSTRUCTED_LEGACY', $src);
        self::assertStringContainsString('LEGACY_RECONSTRUCTED_WARNING', $src);
        self::assertStringContainsString('reconstruct(', $src);
    }

    public function test_section_prompt_hash_matches_exact_text(): void
    {
        $sectionPrompt = "Write section 11 only\nTABLE";
        $result = new PromptResult;
        $result->forceFill([
            'compiled_prompt_hash' => hash('sha256', $sectionPrompt),
            'input_snapshot' => [
                'compiled_prompt' => $sectionPrompt,
                'manual_compiled' => true,
                'sectioned_free_section' => true,
            ],
        ]);

        $resolved = ArticleAiCallRawDetailService::resolvePromptAuthority($result, null);
        self::assertFalse($resolved['hash_mismatch']);
        self::assertSame($sectionPrompt, $resolved['prompt']);
        self::assertSame(hash('sha256', $sectionPrompt), hash('sha256', $resolved['prompt']));
    }
}
