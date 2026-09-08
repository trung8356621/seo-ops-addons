<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeSectionUnit;

/**
 * Isolation for MULTIPLE_PASS compiled Writing prompts (SAME SeoPrompt + section scope).
 */
final class WritingMultiplePassPromptIsolationGuard
{
    public function assertCompiledSectionPrompt(
        string $compiledPrompt,
        SectionedFreeSectionUnit $unit,
        string $fullOutlineMarkdown,
    ): void {
        if (! str_contains($compiledPrompt, 'WRITING SCOPE: SECTION')) {
            throw new PromptRunException(
                'MULTIPLE_PASS compiled prompt missing writing_scope section instructions.',
                0,
                null,
                [
                    'failure_code' => 'WRITING_SPLIT_SCOPE_MISSING',
                    'section_id' => $unit->sectionId,
                    'retryable' => false,
                ],
            );
        }

        if (str_contains($compiledPrompt, 'previous generated output')
            && str_contains(mb_strtolower($compiledPrompt), 'previous section output:')
        ) {
            throw new PromptRunException(
                'MULTIPLE_PASS compiled prompt must not include previous generated output.',
                0,
                null,
                [
                    'failure_code' => 'WRITING_SPLIT_PREVIOUS_OUTPUT_LEAK',
                    'section_id' => $unit->sectionId,
                    'retryable' => false,
                ],
            );
        }

        $full = trim($fullOutlineMarkdown);
        $slice = trim($unit->scopeMarkdown());
        if ($full !== '' && $slice !== '' && mb_strlen($full) > mb_strlen($slice) + 80) {
            // Detect obvious full-outline leak: many H2 markers beyond the current slice.
            $fullH2 = preg_match_all('/^##\s+/mu', $full) ?: 0;
            $sliceH2 = preg_match_all('/^##\s+/mu', $slice) ?: 0;
            $fullH3 = preg_match_all('/^###\s+/mu', $full) ?: 0;
            $sliceH3 = preg_match_all('/^###\s+/mu', $slice) ?: 0;
            if ($fullH2 >= 3 && $sliceH2 <= 1 && substr_count($compiledPrompt, '## ') >= $fullH2) {
                throw new PromptRunException(
                    'MULTIPLE_PASS compiled prompt appears to contain full Outline.',
                    0,
                    null,
                    [
                        'failure_code' => 'WRITING_SPLIT_FULL_OUTLINE_LEAK',
                        'section_id' => $unit->sectionId,
                        'retryable' => false,
                    ],
                );
            }
            if ($fullH3 >= 4 && $sliceH3 <= 1 && substr_count($compiledPrompt, '### ') >= $fullH3) {
                throw new PromptRunException(
                    'MULTIPLE_PASS compiled prompt appears to contain full Outline H3s.',
                    0,
                    null,
                    [
                        'failure_code' => 'WRITING_SPLIT_FULL_OUTLINE_LEAK',
                        'section_id' => $unit->sectionId,
                        'retryable' => false,
                    ],
                );
            }
        }
    }
}
