<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\SectionedFree;

/**
 * Compact section prompt for free models — no full outline dump, no raw vocabulary.
 */
final class SectionedFreeSectionPromptBuilder
{
    /**
     * @param  array{
     *   title?: string,
     *   primary_keyword?: string,
     *   keyword?: string,
     *   intent?: string,
     *   search_intent?: string,
     *   content_intent?: string,
     *   language?: string,
     *   article_map?: list<string>,
     *   suggested_keywords?: list<string>,
     *   previous_section_summary?: string
     * }  $articleContext
     */
    public function build(SectionedFreeSectionUnit $unit, array $articleContext): string
    {
        $title = trim((string) ($articleContext['title'] ?? ''));
        $keyword = trim((string) (
            $articleContext['primary_keyword']
            ?? $articleContext['keyword']
            ?? ''
        ));
        $intent = trim((string) (
            $articleContext['intent']
            ?? $articleContext['search_intent']
            ?? $articleContext['content_intent']
            ?? ''
        ));
        $map = is_array($articleContext['article_map'] ?? null)
            ? $articleContext['article_map']
            : [];
        $suggested = is_array($articleContext['suggested_keywords'] ?? null)
            ? $articleContext['suggested_keywords']
            : [];

        $lines = [
            'You are writing ONE SECTION of a larger article.',
            '',
            'Article title:',
            $title !== '' ? $title : '(untitled)',
            '',
            'Primary keyword:',
            $keyword !== '' ? $keyword : '(none)',
        ];

        if ($intent !== '') {
            $lines[] = '';
            $lines[] = 'Intent:';
            $lines[] = $intent;
        }

        $lines[] = '';
        $lines[] = 'ARTICLE MAP:';
        if ($map === []) {
            $lines[] = '- '.$unit->label;
        } else {
            foreach ($map as $index => $heading) {
                $lines[] = ($index + 1).'. '.$heading;
            }
        }

        $lines[] = '';
        $lines[] = 'CURRENT SECTION:';
        $lines[] = ($unit->order + 1).'. '.$unit->label;
        $lines[] = '';
        $lines[] = 'Subtopics / required points:';
        if ($unit->requiredPoints !== []) {
            foreach ($unit->requiredPoints as $point) {
                $lines[] = '- '.$point;
            }
        } else {
            foreach ($unit->outlineNodes as $node) {
                $heading = trim((string) ($node['heading'] ?? ''));
                $body = trim((string) ($node['body'] ?? ''));
                if ($heading !== '') {
                    $lines[] = '- '.$heading;
                }
                if ($body !== '') {
                    foreach (preg_split('/\R/u', $body) ?: [] as $bodyLine) {
                        $bodyLine = trim($bodyLine);
                        if ($bodyLine !== '') {
                            $lines[] = '  '.$bodyLine;
                        }
                    }
                }
            }
        }

        $lines[] = '';
        $lines[] = 'Suggested keywords:';
        if ($suggested === []) {
            $lines[] = 'none';
        } else {
            foreach ($suggested as $kw) {
                $lines[] = '- '.$kw;
            }
        }
        $lines[] = 'Use suggested keywords naturally when relevant. Do not force keyword usage.';

        $lines[] = '';
        $lines[] = 'Write only CURRENT SECTION.';
        $lines[] = 'Target length:';
        $lines[] = $unit->targetMinWords.'–'.$unit->targetMaxWords.' words (preferred ~'
            .$unit->preferredTargetWords.').';

        if (! $unit->emitParentHeading && $unit->parentH2 !== null && $unit->parentH2 !== '') {
            $lines[] = '';
            $lines[] = 'This chunk CONTINUES under H2: '.$unit->parentH2;
            $lines[] = 'Do NOT repeat the parent H2 heading. Start with H3/subheadings only.';
        }

        $lines[] = '';
        $lines[] = 'Do not write:';
        $lines[] = '- the full article';
        $lines[] = '- sections outside CURRENT SECTION';
        $lines[] = '- a new article introduction unless assigned';
        $lines[] = '- article conclusion unless assigned';
        $lines[] = '- FAQ unless assigned';
        $lines[] = '';
        $lines[] = 'Avoid repeating information that belongs to other sections.';
        if ($unit->emitParentHeading) {
            $lines[] = 'OUTPUT: Markdown for this section only. Include the section heading(s).';
        } else {
            $lines[] = 'OUTPUT: Markdown for this continuation only. Do not re-emit the parent H2.';
        }

        // Hard guarantee: never append raw vocabulary / full outline / word allocation.
        return implode("\n", $lines);
    }

    /**
     * @param  list<SectionedFreeSectionUnit>  $units
     * @return list<string>
     */
    public function buildArticleMap(array $units): array
    {
        $map = [];
        foreach ($units as $unit) {
            $map[] = $unit->label;
        }

        return $map;
    }
}
