<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptPartsCompiler;
use PHPUnit\Framework\TestCase;

final class SeoPromptPartsCompilerTest extends TestCase
{
    public function test_global_constraints_are_not_dropped_from_flat_string(): void
    {
        $prompt = $this->prompt(<<<'MD'
# Ràng buộc tổng (Global)
Never invent product facts.

# Nhiệm vụ
Write one meta description for {{title}}.
MD);

        $compiled = (new SeoPromptPartsCompiler)->compile($prompt, ['title' => 'Canvas bag']);

        self::assertStringContainsString('Ràng buộc tổng (Global)', $compiled->toFlatString());
        self::assertStringContainsString('Never invent product facts.', $compiled->toFlatString());
        self::assertStringContainsString('Write one meta description for Canvas bag.', $compiled->toFlatString());
    }

    public function test_structured_buckets_place_global_constraints_in_system_and_task_in_user(): void
    {
        $prompt = $this->prompt(<<<'MD'
# Vai trò
SEO copywriter.

# Ràng buộc tổng (Global)
Keep claims verifiable.

# Nhiệm vụ
Suggest a title for {{topic}}.
MD);

        $compiled = (new SeoPromptPartsCompiler)->compile($prompt, ['topic' => 'balo']);

        $system = $compiled->systemText();
        $user = $compiled->userText();

        self::assertStringContainsString('Keep claims verifiable.', $system);
        self::assertStringContainsString('SEO copywriter.', $system);
        self::assertStringContainsString('Suggest a title for balo.', $user);
        self::assertStringNotContainsString('Keep claims verifiable.', $user);
    }

    public function test_global_constraints_not_duplicated_in_flat_output(): void
    {
        $prompt = $this->prompt(<<<'MD'
# Ràng buộc tổng (Global)
Only once.

# Nhiệm vụ
Do the work.
MD);

        $flat = (new SeoPromptPartsCompiler)->compile($prompt)->toFlatString();
        self::assertSame(1, substr_count($flat, 'Only once.'));
        self::assertSame(1, substr_count($flat, 'Ràng buộc tổng (Global)'));
    }

    public function test_chain_step_includes_global_constraints_before_separator(): void
    {
        $prompt = $this->prompt(<<<'MD'
# Ràng buộc tổng (Global)
Brand voice: calm.

# Nhiệm vụ
Parent plan.

# Nhiệm vụ phụ thuộc: Render
Create image.
MD);

        $compiler = new SeoPromptPartsCompiler;
        $parts = $prompt->resolvedParts();
        $sub = $parts->first(fn ($p) => (string) $p->role === 'sub_task');
        self::assertNotNull($sub);

        $chain = $compiler->compileChainStep($prompt, $sub, []);
        self::assertStringContainsString('Brand voice: calm.', $chain);
        self::assertStringContainsString("\n\n---\n\n", $chain);
        self::assertStringContainsString('Create image.', $chain);
        // Parent task body must not sit in the step side when compiling a sub_task step.
        $after = explode("\n\n---\n\n", $chain, 2)[1] ?? '';
        self::assertStringContainsString('Create image.', $after);
        self::assertStringNotContainsString('Parent plan.', $after);
    }

    public function test_hook_contract_text_is_not_invented_by_parts_compiler(): void
    {
        $prompt = $this->prompt(<<<'MD'
# Nhiệm vụ
Base prompt only.
MD);

        $flat = (new SeoPromptPartsCompiler)->compile($prompt)->toFlatString();
        self::assertStringNotContainsString('Hook constraints', $flat);
        self::assertStringNotContainsString('Return exactly one meta description', $flat);
    }

    public function test_prompt_runner_delegates_to_lossless_parts_compiler(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/PromptRunnerService.php',
        );
        self::assertStringContainsString('compileInstructions(', $src);
        self::assertStringContainsString('partsCompiler()', $src);
        self::assertStringContainsString('toFlatString()', $src);
        // Former drop of global_constraints must not remain in PromptRunnerService.
        self::assertStringNotContainsString(
            "if ((string) \$part->role === 'global_constraints')",
            $src,
        );
    }

    private function prompt(string $markdown): SeoPrompt
    {
        $prompt = new SeoPrompt;
        $prompt->forceFill([
            'id' => 1,
            'markdown_content' => $markdown,
            'hook_key' => 'article.meta_description_suggestion',
            'is_active' => true,
        ]);

        return $prompt;
    }
}
