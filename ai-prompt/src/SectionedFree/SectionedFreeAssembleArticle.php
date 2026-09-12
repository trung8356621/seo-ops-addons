<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\SectionedFree;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Support\PromptTextMetrics;

/**
 * Deterministic assemble — NEVER calls an LLM.
 *
 * Contract:
 * - AI child outputs CONTENT for the current slice (may omit structural headings).
 * - Assembler owns structural H2/H3 from plannedUnits outlineNodes (SSOT).
 * - Parent H2 for an H3 group is emitted once (track open parent), never per H3.
 */
final class SectionedFreeAssembleArticle
{
    public function __construct(
        private readonly SectionedFreeChildOutputNormalizer $childNormalizer = new SectionedFreeChildOutputNormalizer(),
    ) {}

    /**
     * @param  list<array{
     *   section_id?: string,
     *   section_order: int,
     *   output: string,
     *   status?: string,
     *   emit_parent_heading?: bool,
     *   parent_h2?: ?string
     * }>  $sections
     * @param  list<SectionedFreeSectionUnit>  $plannedUnits
     */
    public function assemble(array $sections, array $plannedUnits = []): string
    {
        $plannedCount = count($plannedUnits);
        if ($plannedCount > 0 && count($sections) !== $plannedCount) {
            throw new PromptRunException(
                'SECTIONED_FREE_SECTION_FAILED: assemble refused — section row count mismatch.',
                0,
                null,
                [
                    'failure_code' => 'SECTIONED_FREE_SECTION_FAILED',
                    'planned_sections' => $plannedCount,
                    'completed_sections' => count($sections),
                    'retryable' => false,
                ],
            );
        }

        foreach ($sections as $row) {
            $status = (string) ($row['status'] ?? '');
            if ($status !== SectionedFreeRunState::STATUS_COMPLETED || trim((string) ($row['output'] ?? '')) === '') {
                throw new PromptRunException(
                    'SECTIONED_FREE_SECTION_FAILED: assemble refused — incomplete section '
                    .(string) ($row['section_id'] ?? '?'),
                    0,
                    null,
                    [
                        'failure_code' => 'SECTIONED_FREE_SECTION_FAILED',
                        'section_id' => $row['section_id'] ?? null,
                        'planned_sections' => $plannedCount > 0 ? $plannedCount : count($sections),
                        'retryable' => false,
                    ],
                );
            }
        }

        $byId = [];
        foreach ($sections as $index => $row) {
            $id = trim((string) ($row['section_id'] ?? ''));
            if ($id === '') {
                $id = 'section_'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
                $row['section_id'] = $id;
            }
            if (isset($byId[$id])) {
                throw new PromptRunException(
                    'SECTIONED_FREE_ASSEMBLY_MISMATCH: duplicate section_id '.$id,
                    0,
                    null,
                    [
                        'failure_code' => 'SECTIONED_FREE_ASSEMBLY_MISMATCH',
                        'section_id' => $id,
                        'retryable' => false,
                    ],
                );
            }
            $byId[$id] = $row;
        }

        if ($plannedUnits === []) {
            $ordered = array_values($byId);
            usort(
                $ordered,
                static fn (array $a, array $b): int => ((int) $a['section_order']) <=> ((int) $b['section_order']),
            );
            $bodies = [];
            foreach ($ordered as $row) {
                $bodies[] = trim((string) $row['output']);
            }

            return trim(implode("\n\n", $bodies));
        }

        $parts = [];
        $openParentH2 = null;
        foreach ($plannedUnits as $unit) {
            if (! isset($byId[$unit->sectionId])) {
                throw new PromptRunException(
                    'SECTIONED_FREE_SECTION_FAILED: missing completed section '.$unit->sectionId,
                    0,
                    null,
                    [
                        'failure_code' => 'SECTIONED_FREE_SECTION_FAILED',
                        'section_id' => $unit->sectionId,
                        'planned_sections' => $plannedCount,
                        'completed_sections' => count($byId),
                        'retryable' => false,
                    ],
                );
            }
            $childOutput = $this->childNormalizer->stripArticleMetadataWrappers(
                (string) ($byId[$unit->sectionId]['output'] ?? ''),
            );
            $rendered = $this->renderUnit($unit, $childOutput, $openParentH2);
            $openParentH2 = $rendered['open_parent_h2'];
            if ($rendered['markdown'] !== '') {
                $parts[] = $rendered['markdown'];
            }
        }

        $assembled = trim(implode("\n\n", $parts));
        $this->assertStructure($assembled, $plannedUnits);

        return $assembled;
    }

    /**
     * @return array{markdown: string, open_parent_h2: ?string}
     */
    public function renderUnit(
        SectionedFreeSectionUnit $unit,
        string $childOutput,
        ?string $previousParentH2,
    ): array {
        $headingLines = [];
        $openParentH2 = $previousParentH2;
        $plannedLeadings = [];

        $parentH2 = trim((string) ($unit->parentH2 ?? ''));
        if ($parentH2 !== '' && $parentH2 !== $openParentH2) {
            $headingLines[] = '## '.$parentH2;
            $plannedLeadings[] = ['level' => 2, 'title' => $parentH2];
            $openParentH2 = $parentH2;
        }

        foreach ($unit->outlineNodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $emit = array_key_exists('emit_heading', $node) ? (bool) $node['emit_heading'] : true;
            if (! $emit) {
                continue;
            }
            $level = max(1, min(4, (int) ($node['level'] ?? 2)));
            $title = trim((string) ($node['heading'] ?? ''));
            if ($title === '') {
                continue;
            }
            // Intro: never invent structural H2/H3 from planner nodes unless explicitly body-like.
            if ($unit->role === SectionedFreeSectionUnit::ROLE_INTRO) {
                continue;
            }
            $headingLines[] = str_repeat('#', $level).' '.$title;
            $plannedLeadings[] = ['level' => $level, 'title' => $title];
        }

        $normalized = $this->childNormalizer->stripArticleMetadataWrappers(trim($childOutput));
        $body = $this->stripLeadingPlannedHeadings($normalized, $plannedLeadings);
        // Continuation chunks: also strip accidental duplicate open parent H2 at lead.
        if ($parentH2 !== '' && $unit->emitParentHeading === false) {
            $body = $this->stripLeadingParentH2($body, $parentH2);
        }

        $chunks = $headingLines;
        if ($body !== '') {
            $chunks[] = $body;
        }

        return [
            'markdown' => trim(implode("\n\n", $chunks)),
            'open_parent_h2' => $openParentH2,
        ];
    }

    /**
     * @param  list<SectionedFreeSectionUnit>  $plannedUnits
     * @return list<string> e.g. ["## Parent", "### A"]
     */
    public function computeExpectedHeadingSequence(array $plannedUnits): array
    {
        $expected = [];
        $openParentH2 = null;
        foreach ($plannedUnits as $unit) {
            if (! $unit instanceof SectionedFreeSectionUnit) {
                continue;
            }
            $parentH2 = trim((string) ($unit->parentH2 ?? ''));
            if ($parentH2 !== '' && $parentH2 !== $openParentH2) {
                $expected[] = '## '.$parentH2;
                $openParentH2 = $parentH2;
            }
            if ($unit->role === SectionedFreeSectionUnit::ROLE_INTRO) {
                continue;
            }
            foreach ($unit->outlineNodes as $node) {
                if (! is_array($node)) {
                    continue;
                }
                $emit = array_key_exists('emit_heading', $node) ? (bool) $node['emit_heading'] : true;
                if (! $emit) {
                    continue;
                }
                $level = max(1, min(4, (int) ($node['level'] ?? 2)));
                $title = trim((string) ($node['heading'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $expected[] = str_repeat('#', $level).' '.$title;
            }
        }

        return $expected;
    }

    /**
     * @param  list<SectionedFreeSectionUnit>  $plannedUnits
     */
    public function assertStructure(string $assembled, array $plannedUnits): void
    {
        $expected = $this->computeExpectedHeadingSequence($plannedUnits);
        if ($expected === []) {
            return;
        }

        $actual = $this->parseHeadingSequence($assembled);
        $missing = [];
        $cursor = 0;
        foreach ($expected as $need) {
            $foundAt = null;
            for ($i = $cursor; $i < count($actual); $i++) {
                if ($this->headingsMatch($actual[$i], $need)) {
                    $foundAt = $i;
                    break;
                }
            }
            if ($foundAt === null) {
                $missing[] = $need;
            } else {
                $cursor = $foundAt + 1;
            }
        }

        if ($missing !== []) {
            throw new PromptRunException(
                'SECTIONED_FREE_STRUCTURE_MISMATCH: missing planned structural headings.',
                0,
                null,
                [
                    'failure_code' => 'SECTIONED_FREE_STRUCTURE_MISMATCH',
                    'expected_headings' => $expected,
                    'actual_headings' => $actual,
                    'missing_headings' => $missing,
                    'planned_sections' => count($plannedUnits),
                    'completed_sections' => count($plannedUnits),
                    'retryable' => false,
                ],
            );
        }
    }

    /**
     * @param  list<array{word_count?: int, output?: string, section_id?: string}>  $sections
     * @param  list<SectionedFreeSectionUnit>  $plannedUnits
     * @return array{sum_section_words: int, assembled_words: int, delta: int, heading_words: int}
     */
    public function assertWordParity(
        array $sections,
        string $assembled,
        int $tolerance = 40,
        array $plannedUnits = [],
    ): array {
        $sum = 0;
        foreach ($sections as $row) {
            $normalized = $this->childNormalizer->stripArticleMetadataWrappers(
                (string) ($row['output'] ?? ''),
            );
            // Prefer recount after metadata strip so Meta/SEO wrappers do not inflate parity.
            $sum += PromptTextMetrics::wordCount($normalized);
        }
        $headingWords = 0;
        foreach ($this->computeExpectedHeadingSequence($plannedUnits) as $heading) {
            $headingWords += PromptTextMetrics::wordCount(
                trim((string) preg_replace('/^#{1,6}\s+/u', '', $heading)),
            );
        }
        $assembledWords = PromptTextMetrics::wordCount($assembled);
        $expectedFloor = $sum; // body words at least present
        $delta = abs($assembledWords - ($sum + $headingWords));
        $allowed = max($tolerance, (int) floor($sum * 0.08)) + max(8, $headingWords);

        // Assembled must not lose body mass: allow heading overhead but not body disappearance.
        if ($sum > 0 && $assembledWords + $tolerance < (int) floor($sum * 0.85)) {
            throw new PromptRunException(
                'SECTIONED_FREE_ASSEMBLY_MISMATCH: assembled lost section body mass'
                .' sum_section_words='.$sum.' assembled_words='.$assembledWords,
                0,
                null,
                [
                    'failure_code' => 'SECTIONED_FREE_ASSEMBLY_MISMATCH',
                    'planned_sections' => count($sections),
                    'completed_sections' => count($sections),
                    'sum_section_words' => $sum,
                    'assembled_words' => $assembledWords,
                    'heading_words' => $headingWords,
                    'retryable' => false,
                ],
            );
        }

        if ($sum > 0 && $delta > $allowed) {
            throw new PromptRunException(
                'SECTIONED_FREE_ASSEMBLY_MISMATCH: sum_section_words='.$sum
                .' assembled_words='.$assembledWords,
                0,
                null,
                [
                    'failure_code' => 'SECTIONED_FREE_ASSEMBLY_MISMATCH',
                    'planned_sections' => count($sections),
                    'completed_sections' => count($sections),
                    'sum_section_words' => $sum,
                    'assembled_words' => $assembledWords,
                    'heading_words' => $headingWords,
                    'expected_floor' => $expectedFloor,
                    'retryable' => false,
                ],
            );
        }

        return [
            'sum_section_words' => $sum,
            'assembled_words' => $assembledWords,
            'delta' => $delta,
            'heading_words' => $headingWords,
        ];
    }

    /**
     * @return list<string>
     */
    public function parseHeadingSequence(string $markdown): array
    {
        preg_match_all('/^(#{2,4})\s+(.+)$/mu', $markdown, $matches, PREG_SET_ORDER);
        $out = [];
        foreach ($matches as $m) {
            $out[] = $m[1].' '.trim($m[2]);
        }

        return $out;
    }

    /**
     * @param  list<array{level: int, title: string}>  $plannedLeadings
     */
    private function stripLeadingPlannedHeadings(string $content, array $plannedLeadings): string
    {
        $trimmed = ltrim($content);
        if ($trimmed === '' || $plannedLeadings === []) {
            return $trimmed;
        }

        foreach ($plannedLeadings as $planned) {
            $level = (int) $planned['level'];
            $title = trim((string) $planned['title']);
            if ($title === '') {
                continue;
            }
            $hashes = str_repeat('#', max(1, min(4, $level)));
            $quoted = preg_quote($title, '/');
            $pattern = '/^'.preg_quote($hashes, '/').'\s*'.$quoted.'\s*\R*/iu';
            if (preg_match($pattern, $trimmed) === 1) {
                $trimmed = ltrim((string) preg_replace($pattern, '', $trimmed, 1));
            }
        }

        return trim($trimmed);
    }

    private function stripLeadingParentH2(string $content, ?string $parentH2): string
    {
        $trimmed = ltrim($content);
        if ($parentH2 === null || $parentH2 === '') {
            if (preg_match('/^##\s+.+\R+/u', $trimmed) === 1) {
                return trim((string) preg_replace('/^##\s+.+\R+/u', '', $trimmed, 1));
            }

            return $trimmed;
        }

        $quoted = preg_quote($parentH2, '/');
        $pattern = '/^##\s*'.$quoted.'\s*(?:—\s*tiếp)?\s*\R+/iu';
        if (preg_match($pattern, $trimmed) === 1) {
            return trim((string) preg_replace($pattern, '', $trimmed, 1));
        }

        return $trimmed;
    }

    private function headingsMatch(string $actual, string $expected): bool
    {
        $norm = static function (string $h): string {
            $h = trim($h);
            $h = preg_replace('/\s+/u', ' ', $h) ?? $h;

            return mb_strtolower($h);
        };

        return $norm($actual) === $norm($expected);
    }
}
