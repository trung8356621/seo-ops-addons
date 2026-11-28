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
        private readonly SectionedFreeChildOutputNormalizer $childNormalizer = new SectionedFreeChildOutputNormalizer,
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
        $normalized = $this->childNormalizer->stripArticleMetadataWrappers(trim($childOutput));
        if ($this->containsAllManagedMarkers($unit, $normalized)) {
            return $this->renderManagedRows($unit, $normalized, $previousParentH2);
        }

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
            $plannedLeadings[] = ['level' => $level, 'title' => $title];
            // Avoid emitting the same H2 twice when parentH2 already opened it for this unit.
            if ($level === 2 && $openParentH2 !== null && $this->headingTitlesMatch($title, $openParentH2)) {
                continue;
            }
            if ($level === 2) {
                $openParentH2 = $title;
            }
            $headingLines[] = str_repeat('#', $level).' '.$title;
        }

        $body = $normalized;
        // Continuation chunks: strip accidental duplicate open parent H2 at lead before planned headings.
        if ($parentH2 !== '' && $unit->emitParentHeading === false) {
            $body = $this->stripLeadingParentH2($body, $parentH2);
        }
        $body = $this->stripLeadingPlannedHeadings($body, $plannedLeadings);

        $chunks = $headingLines;
        if ($body !== '') {
            $chunks[] = $body;
        }

        return [
            'markdown' => trim(implode("\n\n", $chunks)),
            'open_parent_h2' => $openParentH2,
        ];
    }

    private function containsAllManagedMarkers(SectionedFreeSectionUnit $unit, string $output): bool
    {
        $markers = array_keys($unit->managedRowMarkers());
        if ($markers === []) {
            return false;
        }
        foreach ($markers as $marker) {
            if (substr_count($output, $marker) !== 1) {
                return false;
            }
        }

        return true;
    }

    /** @return array{markdown: string, open_parent_h2: ?string} */
    private function renderManagedRows(
        SectionedFreeSectionUnit $unit,
        string $output,
        ?string $previousParentH2,
    ): array {
        $nodes = [];
        foreach ($unit->outlineNodes as $node) {
            $rowId = trim((string) ($node['row_id'] ?? ''));
            if ($rowId !== '') {
                $nodes[] = ['marker' => $unit->rowMarker($rowId), 'node' => $node];
            }
        }

        $parts = [];
        $openParentH2 = $previousParentH2;
        foreach ($nodes as $index => $entry) {
            $marker = $entry['marker'];
            $start = mb_strpos($output, $marker);
            if ($start === false) {
                continue;
            }
            $contentStart = $start + mb_strlen($marker);
            $nextMarker = $nodes[$index + 1]['marker'] ?? null;
            $end = $nextMarker !== null ? mb_strpos($output, $nextMarker, $contentStart) : false;
            $length = $end === false ? null : $end - $contentStart;
            $content = trim(mb_substr($output, $contentStart, $length));
            $node = $entry['node'];
            $title = trim((string) ($node['heading'] ?? ''));
            $level = max(1, min(4, (int) ($node['level'] ?? 2)));
            $emit = (bool) ($node['emit_heading'] ?? true);

            if ($emit && $title !== '') {
                $heading = str_repeat('#', $level).' '.$title;
                $content = $this->stripLeadingPlannedHeadings($content, [['level' => $level, 'title' => $title]]);
                $parts[] = $heading;
                if ($level === 2) {
                    $openParentH2 = $title;
                }
            }
            if ($content !== '') {
                $parts[] = $content;
            }
        }

        return [
            'markdown' => trim(implode("\n\n", $parts)),
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
                if ($level === 2 && $openParentH2 !== null && $this->headingTitlesMatch($title, $openParentH2)) {
                    continue;
                }
                if ($level === 2) {
                    $openParentH2 = $title;
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

        $consecutiveDuplicates = $this->findConsecutiveDuplicateHeadings($actual);
        if ($consecutiveDuplicates !== []) {
            throw new PromptRunException(
                'SECTIONED_FREE_STRUCTURE_MISMATCH: consecutive duplicate structural headings detected.',
                0,
                null,
                [
                    'failure_code' => 'SECTIONED_FREE_STRUCTURE_MISMATCH',
                    'expected_headings' => $expected,
                    'actual_headings' => $actual,
                    'duplicate_headings' => $consecutiveDuplicates,
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
            return trim($trimmed);
        }

        foreach ($plannedLeadings as $planned) {
            $title = trim((string) ($planned['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $trimmed = $this->stripSingleLeadingHeadingMatch($trimmed, $title, false);
        }

        return trim($trimmed);
    }

    private function stripLeadingParentH2(string $content, ?string $parentH2): string
    {
        $trimmed = ltrim($content);
        if ($trimmed === '') {
            return '';
        }
        if ($parentH2 === null || trim($parentH2) === '') {
            if (preg_match('/^##\s+.+\R+/u', $trimmed) === 1) {
                return trim((string) preg_replace('/^##\s+.+\R+/u', '', $trimmed, 1));
            }

            return trim($trimmed);
        }

        return $this->stripSingleLeadingHeadingMatch($trimmed, trim($parentH2), true);
    }

    private function stripSingleLeadingHeadingMatch(
        string $content,
        string $plannedTitle,
        bool $allowContinuationSuffix,
    ): string {
        $trimmed = ltrim($content);
        if ($trimmed === '') {
            return '';
        }

        // Handle inline leading HTML heading like "<h3>Title</h3>Body..." on the same line.
        if (preg_match('/^<h[1-6]\b[^>]*>(.*?)<\/h[1-6]>\s*(.*)$/isu', $trimmed, $htmlMatch) === 1) {
            $candidateText = trim(strip_tags($htmlMatch[1]));
            if ($this->headingTitlesMatch($candidateText, $plannedTitle, $allowContinuationSuffix)) {
                return trim($htmlMatch[2]);
            }
        }

        $lines = preg_split('/\r\n|\r|\n/', $trimmed) ?: [];
        $firstIndex = null;
        foreach ($lines as $idx => $line) {
            if (trim($line) !== '') {
                $firstIndex = $idx;
                break;
            }
        }
        if ($firstIndex === null) {
            return '';
        }

        $firstLine = trim($lines[$firstIndex]);
        if ($this->plannedHeadingMatchesLine($firstLine, $plannedTitle, $allowContinuationSuffix)) {
            $remaining = array_slice($lines, $firstIndex + 1);

            return trim(implode("\n", $remaining));
        }

        return trim($trimmed);
    }

    private function plannedHeadingMatchesLine(
        string $line,
        string $plannedTitle,
        bool $allowContinuationSuffix = false,
    ): bool {
        $trimmed = trim($line);
        if ($trimmed === '') {
            return false;
        }

        // Never treat list items, blockquotes, or code fences as headings.
        if (preg_match('/^(?:[-*+]\s+|>\s+|```)/u', $trimmed) === 1) {
            return false;
        }

        $candidate = $trimmed;
        if (preg_match('/^#{1,6}\s+(.+?)\s*#*$/u', $trimmed, $m) === 1) {
            $candidate = trim($m[1]);
        } elseif (preg_match('/^<h[1-6]\b[^>]*>(.*?)<\/h[1-6]>$/iu', $trimmed, $m) === 1) {
            $candidate = trim(strip_tags($m[1]));
        } elseif (preg_match('/^(?:\*\*|__)(.+?)(?:\*\*|__)$/u', $trimmed, $m) === 1) {
            $candidate = trim($m[1]);
            // Unwrap nested markdown hashes inside bold, e.g. "**### Title**".
            $candidate = trim((string) preg_replace('/^#{1,6}\s+/u', '', $candidate));
        }

        return $this->headingTitlesMatch($candidate, $plannedTitle, $allowContinuationSuffix);
    }

    private function headingTitlesMatch(
        string $candidate,
        string $plannedTitle,
        bool $allowContinuationSuffix = false,
    ): bool {
        $cleanCandidate = $this->stripInlineMarkdownFormatting($candidate);
        if ($allowContinuationSuffix) {
            $cleanCandidate = (string) preg_replace('/\s*(?:—|–|-)\s*tiếp\s*$/iu', '', $cleanCandidate);
        }
        $cleanPlanned = $this->stripInlineMarkdownFormatting($plannedTitle);

        $normCandidate = $this->normalizeHeadingForComparison($cleanCandidate);
        $normPlanned = $this->normalizeHeadingForComparison($cleanPlanned);
        if ($normCandidate === '' || $normPlanned === '') {
            return false;
        }
        if ($normCandidate === $normPlanned) {
            return true;
        }

        $unnumberedCandidate = $this->stripHeadingNumberingPrefix($normCandidate);
        $unnumberedPlanned = $this->stripHeadingNumberingPrefix($normPlanned);

        return $unnumberedCandidate !== ''
            && $unnumberedPlanned !== ''
            && ($unnumberedCandidate !== $normCandidate || $unnumberedPlanned !== $normPlanned)
            && $unnumberedCandidate === $unnumberedPlanned;
    }

    private function stripInlineMarkdownFormatting(string $text): string
    {
        $out = trim($text);
        $out = (string) preg_replace('/^#{1,6}\s+/u', '', $out);
        $out = (string) preg_replace('/\*\*([^*]+)\*\*/u', '$1', $out);
        $out = (string) preg_replace('/__([^_]+)__/u', '$1', $out);
        $out = (string) preg_replace('/`([^`]+)`/u', '$1', $out);

        return trim($out);
    }

    private function normalizeHeadingForComparison(string $text): string
    {
        $out = trim($text);
        $out = (string) preg_replace('/\s+/u', ' ', $out);
        $out = (string) preg_replace('/\s*[—–-]+\s*/u', ' - ', $out);
        $out = rtrim($out, " \t\n\r\0\x0B.:;!?");
        $out = (string) preg_replace('/\s+/u', ' ', $out);

        return mb_strtolower(trim($out));
    }

    private function stripHeadingNumberingPrefix(string $normalizedHeading): string
    {
        return trim((string) preg_replace(
            '/^(?:\d+(?:\.\d+)*|[ivxlcdm]+)\s*[\.\):\-]\s+/iu',
            '',
            $normalizedHeading,
        ));
    }

    /**
     * @param  list<string>  $actualHeadings
     * @return list<string>
     */
    private function findConsecutiveDuplicateHeadings(array $actualHeadings): array
    {
        $duplicates = [];
        for ($i = 0; $i < count($actualHeadings) - 1; $i++) {
            $a = trim((string) preg_replace('/^#{1,6}\s+/u', '', $actualHeadings[$i]));
            $b = trim((string) preg_replace('/^#{1,6}\s+/u', '', $actualHeadings[$i + 1]));
            if ($this->headingTitlesMatch($a, $b, false)) {
                $duplicates[] = $actualHeadings[$i + 1];
            }
        }

        return $duplicates;
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
