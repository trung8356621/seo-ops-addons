<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeSectionUnit;
use Omnichannel\Addons\AiPrompt\Support\ArticleGenerationStrategy;
use Omnichannel\Addons\AiPrompt\Support\WritingSectionScopeInstructions;
use Omnichannel\Addons\Content\Services\OutlineStructuredRowsNormalizer;

/**
 * Compile SAME Writing SeoPrompt for one MULTIPLE_PASS section slice.
 * Temporarily clears sectioned shape so compilePrompt does not throw.
 * Scope instructions are prepended into input/outline so they always appear in compiled text.
 */
final class WritingSectionPromptCompiler
{
    public function __construct(
        private readonly PromptRunnerService $promptRunner,
    ) {}

    /**
     * @param  array<string, mixed>  $baseVariables
     */
    public function compile(
        SeoPrompt $prompt,
        array $baseVariables,
        SectionedFreeSectionUnit $unit,
    ): string {
        $slice = $unit->scopeMarkdown();
        $kind = $this->kindForUnit($unit);
        $assemblerRendersHeading = $unit->emitParentHeading
            || $unit->role !== SectionedFreeSectionUnit::ROLE_INTRO;
        $scoped = WritingSectionScopeInstructions::wrapSlice(
            $slice,
            $kind,
            $assemblerRendersHeading,
        );

        $vars = $baseVariables;
        // Allow compilePrompt while parent run is sectioned.
        $vars['generation_shape'] = ArticleGenerationStrategy::SinglePass->value;
        $vars['generation_strategy'] = ArticleGenerationStrategy::SinglePass->value;
        $vars['_item_generation_strategy'] = ArticleGenerationStrategy::SinglePass->value;
        $vars['resolved_generation_strategy'] = ArticleGenerationStrategy::SinglePass->value;
        unset($vars['generation_strategy_override']);

        $vars['writing_scope'] = WritingSectionScopeInstructions::SCOPE_SECTION;
        $vars['writing_scope_instructions'] = WritingSectionScopeInstructions::forSection(
            $kind,
            $assemblerRendersHeading,
        );
        $vars['writing_section_kind'] = $kind;
        $vars['writing_section_id'] = $unit->sectionId;
        $vars['writing_parent_h2_title'] = $unit->parentH2;
        $vars['pass_mode'] = 'multiple_pass';

        // Force slice into every common Writing placeholder — scope block is inside.
        $vars['input'] = $scoped;
        $vars['article_outline'] = $scoped;
        $vars['outline'] = $scoped;
        $vars['article_writing_raw_input'] = $slice;

        return $this->promptRunner->compilePrompt($prompt, $this->stringifyVars($vars));
    }

    private function kindForUnit(SectionedFreeSectionUnit $unit): string
    {
        return match ($unit->role) {
            SectionedFreeSectionUnit::ROLE_INTRO => OutlineStructuredRowsNormalizer::KIND_INTRO,
            SectionedFreeSectionUnit::ROLE_FAQ => OutlineStructuredRowsNormalizer::KIND_FAQ,
            SectionedFreeSectionUnit::ROLE_CONCLUSION => OutlineStructuredRowsNormalizer::KIND_CONCLUSION,
            default => OutlineStructuredRowsNormalizer::KIND_H3,
        };
    }

    /**
     * @param  array<string, mixed>  $vars
     * @return array<string, string>
     */
    private function stringifyVars(array $vars): array
    {
        $out = [];
        foreach ($vars as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                continue;
            }
            if ($value === null) {
                $out[$key] = '';
                continue;
            }
            if (is_bool($value)) {
                $out[$key] = $value ? '1' : '0';
                continue;
            }
            $out[$key] = (string) $value;
        }

        return $out;
    }
}
