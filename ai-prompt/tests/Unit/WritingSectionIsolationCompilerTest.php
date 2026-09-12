<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeSectionUnit;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\AiPrompt\Services\WritingMultiplePassStepPlanner;
use Omnichannel\Addons\AiPrompt\Services\WritingSectionPromptCompiler;
use Omnichannel\Addons\AiPrompt\Support\WritingMultiplePassPromptIsolationGuard;
use Omnichannel\Addons\AiPrompt\Support\WritingSectionScopeInstructions;
use Omnichannel\Addons\Content\Services\OutlineStructuredRowsNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Sectioned writing isolation: full Outline → section allocation → compiled child.
 */
final class WritingSectionIsolationCompilerTest extends TestCase
{
    private const FULL_OUTLINE = <<<'MD'
[MỞ BÀI]
Intro only.

## Heading A
Point A1

### Heading A.1
Detail A1

## Heading B
Point B1

### Heading B.1
Detail B1

### Heading B.2
Detail B2

## Heading C
Point C1
MD;

    private const VOCABULARY = <<<'MD'
- term alpha
- term beta
- term gamma unique vocab marker XYZ
MD;

    public function test_a_full_outline_to_section_allocation_subtree_only(): void
    {
        $plan = $this->plan(self::FULL_OUTLINE);
        self::assertGreaterThan(1, count($plan->units));

        $unitB = $this->unitByParent($plan->units, 'Heading B');
        self::assertNotNull($unitB);

        $slice = $unitB->scopeMarkdown();
        self::assertStringContainsString('Heading B', $slice);
        self::assertStringContainsString('Heading B.1', $slice);
        self::assertStringNotContainsString('Heading A', $slice);
        self::assertStringNotContainsString('Heading C', $slice);
    }

    public function test_b_no_full_outline_leak_in_compiled_section_b(): void
    {
        $plan = $this->plan(self::FULL_OUTLINE);
        $unitB = $this->unitByParent($plan->units, 'Heading B');
        self::assertNotNull($unitB);

        $compiled = $this->compileWithFakeRunner($unitB, self::FULL_OUTLINE, self::VOCABULARY);

        self::assertStringContainsString('WRITING SCOPE: SECTION', $compiled);
        self::assertStringContainsString('Heading B', $compiled);
        self::assertStringContainsString('Heading B.1', $compiled);
        self::assertStringNotContainsString('## Heading A', $compiled);
        self::assertStringNotContainsString('## Heading C', $compiled);
        self::assertStringNotContainsString('DYNAMIC WORD ALLOCATION', $compiled);

        (new WritingMultiplePassPromptIsolationGuard())
            ->assertCompiledSectionPrompt($compiled, $unitB, self::FULL_OUTLINE);
    }

    public function test_c_vocabulary_preserved_on_section_child_vars(): void
    {
        $plan = $this->plan(self::FULL_OUTLINE);
        $unit = $plan->units[0];
        $capturedVars = null;

        $runner = $this->createMock(PromptRunnerService::class);
        $runner->method('compilePrompt')->willReturnCallback(
            function (SeoPrompt $prompt, array $vars) use (&$capturedVars, $unit): string {
                $capturedVars = $vars;

                return WritingSectionScopeInstructions::wrapSlice(
                    $unit->scopeMarkdown(),
                    'intro',
                    true,
                )."\n## Fake Role\nbody";
            },
        );

        $compiler = new WritingSectionPromptCompiler($runner);
        $compiler->compile($this->fakePrompt(), [
            'article_outline' => self::FULL_OUTLINE,
            'outline' => self::FULL_OUTLINE,
            'input' => self::FULL_OUTLINE,
            'article_vocabulary' => self::VOCABULARY,
            'article_length' => '2000',
            'title' => 'T',
            'keyword' => 'K',
            'language' => 'vi',
        ], $unit);

        self::assertIsArray($capturedVars);
        self::assertSame(self::VOCABULARY, $capturedVars['article_vocabulary'] ?? null);
        self::assertStringContainsString('term alpha', (string) $capturedVars['article_vocabulary']);
    }

    public function test_d_global_context_preserved(): void
    {
        $plan = $this->plan(self::FULL_OUTLINE);
        $unit = $plan->units[0];
        $capturedVars = null;

        $runner = $this->createMock(PromptRunnerService::class);
        $runner->method('compilePrompt')->willReturnCallback(
            function (SeoPrompt $prompt, array $vars) use (&$capturedVars, $unit): string {
                $capturedVars = $vars;

                return WritingSectionScopeInstructions::wrapSlice($unit->scopeMarkdown(), 'intro', true);
            },
        );

        (new WritingSectionPromptCompiler($runner))->compile($this->fakePrompt(), [
            'article_outline' => self::FULL_OUTLINE,
            'article_vocabulary' => self::VOCABULARY,
            'title' => 'Article Title',
            'post_title' => 'Article Title',
            'keyword' => 'focus kw',
            'focus_keyword' => 'focus kw',
            'language' => 'Vietnamese',
            'site_short_description' => 'Site biz context',
            'tone' => 'professional',
        ], $unit);

        self::assertSame('Article Title', $capturedVars['title'] ?? null);
        self::assertSame('focus kw', $capturedVars['keyword'] ?? $capturedVars['focus_keyword'] ?? null);
        self::assertSame('Vietnamese', $capturedVars['language'] ?? null);
        self::assertSame('Site biz context', $capturedVars['site_short_description'] ?? null);
    }

    public function test_e_single_pass_keeps_full_outline_outside_section_compiler(): void
    {
        // Single-pass does not route through WritingSectionPromptCompiler — full outline stays on vars.
        $vars = [
            'outline' => self::FULL_OUTLINE,
            'article_outline' => self::FULL_OUTLINE,
            'input' => self::FULL_OUTLINE,
            'generation_shape' => 'single_pass',
        ];
        self::assertStringContainsString('## Heading A', $vars['outline']);
        self::assertStringContainsString('## Heading C', $vars['article_outline']);
        self::assertSame('single_pass', $vars['generation_shape']);
    }

    public function test_f_multiple_section_isolation_differs_per_child(): void
    {
        $plan = $this->plan(self::FULL_OUTLINE);
        $unitA = $this->unitByParent($plan->units, 'Heading A');
        $unitB = $this->unitByParent($plan->units, 'Heading B');
        self::assertNotNull($unitA);
        self::assertNotNull($unitB);

        $compiledA = $this->compileWithFakeRunner($unitA, self::FULL_OUTLINE, self::VOCABULARY);
        $compiledB = $this->compileWithFakeRunner($unitB, self::FULL_OUTLINE, self::VOCABULARY);

        self::assertStringContainsString('Heading A', $compiledA);
        self::assertStringNotContainsString('## Heading B', $compiledA);
        self::assertStringContainsString('Heading B', $compiledB);
        self::assertStringNotContainsString('## Heading A', $compiledB);
        self::assertNotSame($compiledA, $compiledB);
    }

    public function test_g_leak_guard_still_fires_on_injected_full_outline(): void
    {
        $plan = $this->plan(self::FULL_OUTLINE);
        $unitB = $this->unitByParent($plan->units, 'Heading B');
        self::assertNotNull($unitB);

        $scoped = WritingSectionScopeInstructions::wrapSlice($unitB->scopeMarkdown(), 'h3', true);
        $contaminated = $scoped."\n\n## Heading A\nPoint A1\n\n## Heading C\nPoint C1\n";

        try {
            (new WritingMultiplePassPromptIsolationGuard())
                ->assertCompiledSectionPrompt($contaminated, $unitB, self::FULL_OUTLINE);
            self::fail('Expected WRITING_SPLIT_FULL_OUTLINE_LEAK');
        } catch (PromptRunException $e) {
            self::assertSame('WRITING_SPLIT_FULL_OUTLINE_LEAK', $e->context['failure_code'] ?? null);
        }
    }

    public function test_strip_removes_dynamic_word_allocation_block(): void
    {
        $compiler = new WritingSectionPromptCompiler($this->createMock(PromptRunnerService::class));
        $raw = <<<'TXT'
## Bối cảnh
Keep me

## ROLE & GOAL
Whole article goal

## DYNAMIC WORD ALLOCATION FOR ARTICLE BODY
target whole article 2000 words
80% body plan

## STRICT LENGTH REQUIREMENT
Must be 2000 words

## SEO, GEO & FACTUAL INTEGRITY
Keep SEO
TXT;
        $out = $compiler->stripWholeArticleAllocationBlocks($raw);
        self::assertStringContainsString('Keep me', $out);
        self::assertStringContainsString('Keep SEO', $out);
        self::assertStringNotContainsString('DYNAMIC WORD ALLOCATION', $out);
        self::assertStringNotContainsString('ROLE & GOAL', $out);
        self::assertStringNotContainsString('STRICT LENGTH REQUIREMENT', $out);
        self::assertStringNotContainsString('2000 words', $out);
    }

    public function test_section_article_length_uses_section_target_not_2000(): void
    {
        $plan = $this->plan(self::FULL_OUTLINE);
        $unit = $plan->units[0];
        $capturedVars = null;

        $runner = $this->createMock(PromptRunnerService::class);
        $runner->method('compilePrompt')->willReturnCallback(
            function (SeoPrompt $prompt, array $vars) use (&$capturedVars, $unit): string {
                $capturedVars = $vars;

                return WritingSectionScopeInstructions::wrapSlice($unit->scopeMarkdown(), 'intro', true);
            },
        );

        (new WritingSectionPromptCompiler($runner))->compile($this->fakePrompt(), [
            'article_outline' => self::FULL_OUTLINE,
            'article_length' => '2000',
            'target_article_length' => '2000',
            'article_vocabulary' => self::VOCABULARY,
        ], $unit);

        self::assertSame((string) $unit->preferredTargetWords, $capturedVars['article_length'] ?? null);
        self::assertNotSame('2000', $capturedVars['article_length'] ?? '2000');
    }

    /**
     * @param  list<SectionedFreeSectionUnit>  $units
     */
    private function unitByParent(array $units, string $parentH2): ?SectionedFreeSectionUnit
    {
        foreach ($units as $unit) {
            if (trim((string) $unit->parentH2) === $parentH2
                || str_contains($unit->scopeMarkdown(), '## '.$parentH2)
            ) {
                return $unit;
            }
        }

        return null;
    }

    private function plan(string $outline): \Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreePlan
    {
        $rows = (new OutlineStructuredRowsNormalizer())->normalize($outline);

        return (new WritingMultiplePassStepPlanner())->planFromRows($rows);
    }

    private function fakePrompt(): SeoPrompt
    {
        $prompt = new SeoPrompt;
        $prompt->id = 1;
        $prompt->hook_key = 'article.content.generate';
        $prompt->name = 'test';

        return $prompt;
    }

    private function compileWithFakeRunner(
        SectionedFreeSectionUnit $unit,
        string $fullOutline,
        string $vocabulary,
    ): string {
        $runner = $this->createMock(PromptRunnerService::class);
        $runner->method('compilePrompt')->willReturnCallback(
            function (SeoPrompt $prompt, array $vars) use ($unit): string {
                // Simulate shared Writing SeoPrompt: role heading + whole-article block + input.
                $body = "## Bối cảnh\n"
                    ."## ROLE & GOAL\nWrite article\n"
                    ."## DYNAMIC WORD ALLOCATION FOR ARTICLE BODY\nWhole article 2000 words\n"
                    ."## SEO, GEO & FACTUAL INTEGRITY\nKeep\n"
                    ."## INPUT DATA\n".(string) ($vars['input'] ?? '');

                return $body;
            },
        );

        return (new WritingSectionPromptCompiler($runner))->compile($this->fakePrompt(), [
            'article_outline' => $fullOutline,
            'outline' => $fullOutline,
            'input' => $fullOutline,
            'article_vocabulary' => $vocabulary,
            'article_length' => '2000',
            'title' => 'T',
            'keyword' => 'K',
            'language' => 'vi',
        ], $unit);
    }
}
