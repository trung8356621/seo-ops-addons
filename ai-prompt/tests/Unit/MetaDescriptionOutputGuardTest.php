<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookException;
use Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookManifestLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookOutputNormalizer;
use Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookRegistry;
use Omnichannel\Addons\AiPrompt\Support\MetaDescriptionOutputGuard;
use PHPUnit\Framework\TestCase;

final class MetaDescriptionOutputGuardTest extends TestCase
{
    public function test_rejects_instruction_echo(): void
    {
        $raw = <<<'TXT'
- Return exactly one meta description paragraph as plain text.
- Do not add explanations, prefixes (e.g. "Meta description:"), markdown, or quotes.
- Base the description on the title: May Túi vải canvas giá rẻ Hợp Phát
TXT;
        self::assertTrue(MetaDescriptionOutputGuard::looksLikePromptInstruction($raw));
        self::assertSame('', MetaDescriptionOutputGuard::extractCandidate($raw));
    }

    public function test_rejects_prod_4831_instruction_peel(): void
    {
        // Exact production PromptResult #4831 shape — previously peeled a false candidate.
        $raw = <<<'TXT'
- Return exactly one meta description paragraph as plain text.
- Do not add explanations, prefixes (e.g. "Meta description:"), markdown, or quotes.
- Base the description on the title: May Túi vải canvas giá rẻ Hợp Phát
- If the old_description is not null, use it as context to improve: Thông tin đặt hàng: Tên mặt hàng: Túi vải Canvas Hợp Phát. Thành phần nguyên liệu: – Sản phẩm sử dụng thành phần chính là bố vải 100% cotton .
TXT;
        self::assertTrue(MetaDescriptionOutputGuard::looksLikePromptInstruction($raw));
        $candidate = MetaDescriptionOutputGuard::extractCandidate($raw);
        self::assertSame('', $candidate);
        self::assertTrue(MetaDescriptionOutputGuard::looksLikePromptInstruction(
            'If the old_description is not null, use it as context to improve: Thông tin đặt hàng test',
        ));
    }

    public function test_sanitize_old_description_for_prompt(): void
    {
        self::assertNull(MetaDescriptionOutputGuard::sanitizeOldDescriptionForPrompt(null));
        self::assertNull(MetaDescriptionOutputGuard::sanitizeOldDescriptionForPrompt('null'));
        self::assertNull(MetaDescriptionOutputGuard::sanitizeOldDescriptionForPrompt(
            'If the old_description is not null, use it as context to improve: x',
        ));
        $long = str_repeat('a', MetaDescriptionOutputGuard::OLD_DESCRIPTION_CONTEXT_MAX + 1);
        self::assertNull(MetaDescriptionOutputGuard::sanitizeOldDescriptionForPrompt($long));
        self::assertSame(
            'Mô tả ngắn hợp lệ cho SERP và sản phẩm canvas.',
            MetaDescriptionOutputGuard::sanitizeOldDescriptionForPrompt(
                'Mô tả ngắn hợp lệ cho SERP và sản phẩm canvas.',
            ),
        );
    }

    public function test_accepts_real_meta_description(): void
    {
        $meta = 'May túi vải canvas giá rẻ tại Hợp Phát — bền chắc, in logo theo yêu cầu, giao hàng toàn quốc.';
        self::assertFalse(MetaDescriptionOutputGuard::looksLikePromptInstruction($meta));
        self::assertSame($meta, MetaDescriptionOutputGuard::extractCandidate($meta));
    }

    public function test_rejects_task_preamble_and_requirement_fragment(): void
    {
        self::assertTrue(MetaDescriptionOutputGuard::looksLikePromptInstruction(
            'Task: Write an SEO meta description based on the following data (use all punycode for tags and attributes):',
        ));
        self::assertTrue(MetaDescriptionOutputGuard::looksLikePromptInstruction('Requirement:'));
        self::assertSame('', MetaDescriptionOutputGuard::extractCandidate(
            'Task: Write an SEO meta description based on the following data:',
        ));
    }

    public function test_rejects_title_restatement_wrapper(): void
    {
        $title = 'May Túi vải canvas giá rẻ Hợp Phát';
        self::assertTrue(MetaDescriptionOutputGuard::looksLikeTitleRestatement(
            'Stick to the title: May Túi vải canvas giá rẻ Hợp Phát',
            $title,
        ));
        self::assertFalse(MetaDescriptionOutputGuard::looksLikeTitleRestatement(
            'May túi vải canvas giá rẻ Hợp Phát là lựa chọn tiết kiệm, phù hợp nhu cầu sử dụng hằng ngày.',
            $title,
        ));
    }

    public function test_rejects_requirements_bullet_list(): void
    {
        $raw = "Requirements:\n- Follow the title structure.\n- Improve on the old description if there is one.\n- Length between 120 and 160 characters.";
        self::assertTrue(MetaDescriptionOutputGuard::looksLikePromptInstruction($raw));
    }

    public function test_normalizer_rejects_instruction_echo_for_meta_hook(): void
    {
        $definition = (new PromptHookRegistry(
            new PromptHookManifestLoader(PromptHookManifestLoader::defaultDirectory()),
        ))->get('article.meta_description_suggestion');

        $this->expectException(PromptHookException::class);
        $this->expectExceptionMessage('prompt instructions');

        (new PromptHookOutputNormalizer)->normalize(
            $definition,
            '- Return exactly one meta description paragraph as plain text.',
            ['title' => 'Test'],
        );
    }

    public function test_normalizer_rejects_prod_4831_payload(): void
    {
        $definition = (new PromptHookRegistry(
            new PromptHookManifestLoader(PromptHookManifestLoader::defaultDirectory()),
        ))->get('article.meta_description_suggestion');

        $raw = <<<'TXT'
- Return exactly one meta description paragraph as plain text.
- Do not add explanations, prefixes (e.g. "Meta description:"), markdown, or quotes.
- Base the description on the title: May Túi vải canvas giá rẻ Hợp Phát
- If the old_description is not null, use it as context to improve: Thông tin đặt hàng: Túi vải Canvas Hợp Phát.
TXT;

        $this->expectException(PromptHookException::class);
        $this->expectExceptionMessage('prompt instructions');

        (new PromptHookOutputNormalizer)->normalize(
            $definition,
            $raw,
            ['title' => 'May Túi vải canvas giá rẻ Hợp Phát'],
        );
    }

    public function test_adapter_empty_diagnostics_mention_reasoning_class(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ProviderTemplates/OpenAiCompatibleProtocolAdapter.php',
        );
        self::assertStringContainsString('empty_class=', $src);
        self::assertStringContainsString('reasoning_truncated', $src);
        self::assertStringContainsString('has_reasoning_content=', $src);
    }
}
