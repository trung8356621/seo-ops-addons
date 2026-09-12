<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeSectionUnit;

/**
 * Isolation for MULTIPLE_PASS compiled Writing prompts (SAME SeoPrompt + section scope).
 *
 * Detects real full-outline allocation leaks (foreign H2 titles as writable headings).
 * Does not treat SeoPrompt instructional "## ROLE & GOAL" headings as outline content.
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
        if ($full === '' || $slice === '' || mb_strlen($full) <= mb_strlen($slice) + 80) {
            return;
        }

        $fullTitles = $this->extractH2Titles($full);
        $sliceTitles = $this->extractH2Titles($slice);
        if (count($fullTitles) < 2) {
            return;
        }

        $foreign = [];
        foreach ($fullTitles as $title) {
            if ($this->titleInList($title, $sliceTitles)) {
                continue;
            }
            $foreign[] = $title;
        }

        if ($foreign === []) {
            return;
        }

        // Outside CURRENT OUTLINE SLICE, foreign H2s must not appear as writable ## headings.
        $outsideSlice = $this->withoutCurrentOutlineSlice($compiledPrompt);
        $leaked = [];
        foreach ($foreign as $title) {
            if ($this->appearsAsMarkdownHeading($outsideSlice, $title)) {
                $leaked[] = $title;
            }
        }

        if ($leaked !== []) {
            throw new PromptRunException(
                'MULTIPLE_PASS compiled prompt appears to contain full Outline.',
                0,
                null,
                [
                    'failure_code' => 'WRITING_SPLIT_FULL_OUTLINE_LEAK',
                    'section_id' => $unit->sectionId,
                    'leaked_headings' => array_slice($leaked, 0, 5),
                    'retryable' => false,
                ],
            );
        }

        // Slice region itself must not equal the full outline allocation.
        $sliceRegion = $this->extractCurrentOutlineSlice($compiledPrompt);
        if ($sliceRegion !== '' && mb_strlen($sliceRegion) > mb_strlen($slice) + 120) {
            $sliceRegionTitles = $this->extractH2Titles($sliceRegion);
            $extraInSlice = 0;
            foreach ($foreign as $title) {
                if ($this->titleInList($title, $sliceRegionTitles)) {
                    $extraInSlice++;
                }
            }
            if ($extraInSlice >= 2) {
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
        }
    }

    /**
     * @return list<string>
     */
    private function extractH2Titles(string $markdown): array
    {
        if (preg_match_all('/^##\s+(.+?)\s*$/mu', $markdown, $matches) !== false) {
            $titles = [];
            foreach ($matches[1] as $raw) {
                $title = $this->normalizeHeadingTitle((string) $raw);
                if ($title !== '') {
                    $titles[] = $title;
                }
            }

            return array_values(array_unique($titles));
        }

        return [];
    }

    private function normalizeHeadingTitle(string $title): string
    {
        $title = trim($title);
        $title = (string) preg_replace('/^H2:\s*/iu', '', $title);

        return trim($title);
    }

    /**
     * @param  list<string>  $titles
     */
    private function titleInList(string $title, array $titles): bool
    {
        $needle = mb_strtolower($this->normalizeHeadingTitle($title));
        foreach ($titles as $candidate) {
            if (mb_strtolower($this->normalizeHeadingTitle($candidate)) === $needle) {
                return true;
            }
        }

        return false;
    }

    private function appearsAsMarkdownHeading(string $prompt, string $title): bool
    {
        $normalized = $this->normalizeHeadingTitle($title);
        if ($normalized === '') {
            return false;
        }

        $quoted = preg_quote($normalized, '/');
        if (preg_match('/^##\s+(?:H2:\s*)?'.$quoted.'\s*$/mu', $prompt) === 1) {
            return true;
        }

        // Also catch "H2: Title" form when outline stored titles with H2: prefix.
        $quotedRaw = preg_quote(trim($title), '/');

        return preg_match('/^##\s+'.$quotedRaw.'\s*$/mu', $prompt) === 1;
    }

    private function withoutCurrentOutlineSlice(string $prompt): string
    {
        return trim((string) preg_replace(
            '/=== CURRENT OUTLINE SLICE ===.*?=== END CURRENT OUTLINE SLICE ===/su',
            '',
            $prompt,
        ));
    }

    private function extractCurrentOutlineSlice(string $prompt): string
    {
        if (preg_match(
            '/=== CURRENT OUTLINE SLICE ===\s*(.*?)\s*=== END CURRENT OUTLINE SLICE ===/su',
            $prompt,
            $m,
        ) === 1) {
            return trim((string) $m[1]);
        }

        return '';
    }
}
