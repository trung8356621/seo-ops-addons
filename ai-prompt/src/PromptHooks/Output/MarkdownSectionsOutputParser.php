<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Output;


use Omnichannel\Addons\AiPrompt\Support\PromptTextMetrics;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookDefinition;
use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookOutputSchema;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\DuplicateOutputSection;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\InvalidSectionOutput;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\MismatchedSectionMarker;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\MissingRequiredSection;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\TextOutsideDeclaredSections;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\UnknownSectionMarker;

/**
 * Definition-driven structured Markdown section parser.
 * Markers are plain delimiters — never execute code.
 */
final class MarkdownSectionsOutputParser
{
    public function parse(
        PromptHookDefinition $definition,
        string $raw,
        ?string $correlationId = null,
    ): MarkdownSectionsParseResult {
        $schema = $definition->outputSchema;
        if (! $schema->isMarkdownSections()) {
            throw new InvalidSectionOutput(
                'markdown_sections parser requires output_schema.type=markdown_sections.',
                $definition->key->value,
                $definition->version->toString(),
            );
        }

        $hookKey = $definition->key->value;
        $hookVersion = $definition->version->toString();
        $sectionsDef = $schema->sections;
        if ($sectionsDef === []) {
            throw new InvalidSectionOutput(
                'markdown_sections requires non-empty output_schema.sections.',
                $hookKey,
                $hookVersion,
                correlationId: $correlationId,
            );
        }

        $declaredMarkers = $this->declaredMarkers($sectionsDef);
        // Normalize before section match: BOM, outer fence, short prologue/epilogue.
        // Undeclared task markers in prologue still fail (checked on pre-slice text).
        $raw = $this->normalizeProviderRaw(
            $raw,
            $declaredMarkers,
            $schema->strictUndeclaredMarkers(),
            $hookKey,
            $hookVersion,
            $correlationId,
        );
        $sections = [];
        $ports = [];
        $consumedSpans = [];

        foreach ($sectionsDef as $section) {
            if (! is_array($section)) {
                continue;
            }

            $key = trim((string) ($section['key'] ?? ''));
            $start = (string) ($section['start_marker'] ?? '');
            $end = (string) ($section['end_marker'] ?? '');
            $required = (bool) ($section['required'] ?? false);
            $port = trim((string) ($section['output_port'] ?? $key));
            $normalize = is_array($section['normalize'] ?? null)
                ? array_values(array_map('strval', $section['normalize']))
                : ['trim', 'strip_markdown_fence'];

            if ($key === '' || $start === '' || $end === '') {
                throw new InvalidSectionOutput(
                    'Section definition missing key/start_marker/end_marker.',
                    $hookKey,
                    $hookVersion,
                    $key,
                    $correlationId,
                );
            }

            $matches = $this->matchSectionPairs($raw, $start, $end);
            if (count($matches) > 1) {
                throw new DuplicateOutputSection(
                    $this->contextMessage($hookKey, $hookVersion, $key, $start, $end, $correlationId, 'Duplicate section.'),
                    $hookKey,
                    $hookVersion,
                    $key,
                    $start,
                    $end,
                    $correlationId,
                );
            }

            if ($matches === []) {
                $startCount = substr_count($raw, $start);
                $endCount = substr_count($raw, $end);
                if ($startCount > 0 || $endCount > 0) {
                    throw new MismatchedSectionMarker(
                        $this->contextMessage($hookKey, $hookVersion, $key, $start, $end, $correlationId, 'Mismatched start/end markers.'),
                        $hookKey,
                        $hookVersion,
                        $key,
                        $start,
                        $end,
                        $correlationId,
                    );
                }
                if ($required) {
                    throw new MissingRequiredSection(
                        $this->contextMessage($hookKey, $hookVersion, $key, $start, $end, $correlationId, 'Required section missing.'),
                        $hookKey,
                        $hookVersion,
                        $key,
                        $start,
                        $end,
                        $correlationId,
                    );
                }

                continue;
            }

            $match = $matches[0];
            $content = $this->normalizeSectionContent((string) $match['content'], $normalize);
            $this->assertNoNestedDeclaredMarkers($content, $declaredMarkers, $hookKey, $hookVersion, $key, $start, $end, $correlationId);
            $this->assertSectionValidation(
                is_array($section['validation'] ?? null) ? $section['validation'] : [],
                $content,
                $hookKey,
                $hookVersion,
                $key,
                $correlationId,
            );

            $sections[$key] = $content;
            $ports[$port] = $content;
            $consumedSpans[] = [$match['full_start'], $match['full_end']];
        }

        if (($schema->validation['require_declared_order'] ?? false) === true) {
            $this->assertDeclaredOrder($raw, $sectionsDef, $hookKey, $hookVersion, $correlationId);
        }

        if ($schema->strictUndeclaredMarkers()) {
            $this->assertNoUndeclaredTaskMarkers($raw, $declaredMarkers, $hookKey, $hookVersion, $correlationId);
        }

        if (! $schema->allowTextOutsideSections()) {
            $this->assertNoTextOutsideSections($raw, $consumedSpans, $hookKey, $hookVersion, $correlationId);
        }

        $totalPort = $schema->totalPort !== '' ? $schema->totalPort : 'total';
        $ports[$totalPort] = $schema->preserveMarkersInTotal()
            ? $raw
            : $this->stripAllDeclaredMarkers($raw, $declaredMarkers);

        return new MarkdownSectionsParseResult(
            sections: $sections,
            ports: $ports,
            raw: $raw,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $sectionsDef
     * @return list<string>
     */
    private function declaredMarkers(array $sectionsDef): array
    {
        $markers = [];
        foreach ($sectionsDef as $section) {
            if (! is_array($section)) {
                continue;
            }
            $start = (string) ($section['start_marker'] ?? '');
            $end = (string) ($section['end_marker'] ?? '');
            if ($start !== '') {
                $markers[] = $start;
            }
            if ($end !== '') {
                $markers[] = $end;
            }
        }

        return array_values(array_unique($markers));
    }

    /**
     * @return list<array{content: string, full_start: int, full_end: int}>
     */
    private function matchSectionPairs(string $raw, string $startMarker, string $endMarker): array
    {
        $pattern = '/'.preg_quote($startMarker, '/').'(.*?)'.preg_quote($endMarker, '/').'/s';
        if (! preg_match_all($pattern, $raw, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $out = [];
        $fullMatches = $matches[0] ?? [];
        $inners = $matches[1] ?? [];
        foreach ($fullMatches as $i => $full) {
            $fullText = (string) ($full[0] ?? '');
            $fullStart = (int) ($full[1] ?? 0);
            $inner = (string) ($inners[$i][0] ?? '');
            $out[] = [
                'content' => $inner,
                'full_start' => $fullStart,
                'full_end' => $fullStart + strlen($fullText),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $validation
     */
    private function assertSectionValidation(
        array $validation,
        string $content,
        string $hookKey,
        string $hookVersion,
        string $sectionKey,
        ?string $correlationId,
    ): void {
        if (($validation['not_empty'] ?? false) === true && trim($content) === '') {
            throw new InvalidSectionOutput(
                $this->contextMessage($hookKey, $hookVersion, $sectionKey, '', '', $correlationId, 'Section content empty.'),
                $hookKey,
                $hookVersion,
                $sectionKey,
                $correlationId,
            );
        }

        if (isset($validation['min_length'])) {
            $unit = strtolower(trim((string) ($validation['length_unit'] ?? 'chars')));
            if ($unit !== 'words') {
                $unit = 'chars';
            }
            $measured = \Omnichannel\Addons\AiPrompt\Support\PromptTextMetrics::measure($content, $unit);
            if ($measured < (int) $validation['min_length']) {
                throw new InvalidSectionOutput(
                    $this->contextMessage(
                        $hookKey,
                        $hookVersion,
                        $sectionKey,
                        '',
                        '',
                        $correlationId,
                        $unit === 'words'
                            ? 'Section shorter than min_length (words).'
                            : 'Section shorter than min_length.',
                    ),
                    $hookKey,
                    $hookVersion,
                    $sectionKey,
                    $correlationId,
                );
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $sectionsDef
     */
    private function assertDeclaredOrder(
        string $raw,
        array $sectionsDef,
        string $hookKey,
        string $hookVersion,
        ?string $correlationId,
    ): void {
        $lastPos = -1;
        foreach ($sectionsDef as $section) {
            if (! is_array($section)) {
                continue;
            }
            $key = trim((string) ($section['key'] ?? ''));
            $start = (string) ($section['start_marker'] ?? '');
            $end = (string) ($section['end_marker'] ?? '');
            if ($start === '') {
                continue;
            }
            $pos = strpos($raw, $start);
            if ($pos === false) {
                continue;
            }
            if ($pos < $lastPos) {
                throw new MismatchedSectionMarker(
                    $this->contextMessage($hookKey, $hookVersion, $key, $start, $end, $correlationId, 'Sections out of declared order.'),
                    $hookKey,
                    $hookVersion,
                    $key,
                    $start,
                    $end,
                    $correlationId,
                );
            }
            $lastPos = $pos;
        }
    }

    /**
     * @param  list<string>  $normalize
     */
    private function normalizeSectionContent(string $content, array $normalize): string
    {
        $value = $content;
        foreach ($normalize as $step) {
            $value = match ($step) {
                'trim' => trim($value),
                'strip_markdown_fence' => $this->stripMarkdownFence($value),
                default => $value,
            };
        }

        return $value;
    }

    private function stripMarkdownFence(string $value): string
    {
        $trimmed = trim($value);
        if (preg_match('/^```(?:\w+)?\s*\n?(.*?)\n?```$/s', $trimmed, $matches) === 1) {
            return trim($matches[1]);
        }

        return $value;
    }

    /**
     * @param  list<string>  $declaredMarkers
     */
    private function assertNoNestedDeclaredMarkers(
        string $content,
        array $declaredMarkers,
        string $hookKey,
        string $hookVersion,
        string $sectionKey,
        string $startMarker,
        string $endMarker,
        ?string $correlationId,
    ): void {
        foreach ($declaredMarkers as $marker) {
            if ($marker !== '' && str_contains($content, $marker)) {
                throw new MismatchedSectionMarker(
                    $this->contextMessage($hookKey, $hookVersion, $sectionKey, $startMarker, $endMarker, $correlationId, 'Nested declared task marker inside section.'),
                    $hookKey,
                    $hookVersion,
                    $sectionKey,
                    $startMarker,
                    $endMarker,
                    $correlationId,
                );
            }
        }
    }

    /**
     * @param  list<string>  $declaredMarkers
     */
    private function assertNoUndeclaredTaskMarkers(
        string $raw,
        array $declaredMarkers,
        string $hookKey,
        string $hookVersion,
        ?string $correlationId,
    ): void {
        if (! preg_match_all('/\[(?:START|END)[^\]]*\]/u', $raw, $matches)) {
            return;
        }

        foreach ($matches[0] as $marker) {
            $marker = (string) $marker;
            if (! in_array($marker, $declaredMarkers, true)) {
                throw new UnknownSectionMarker(
                    $this->contextMessage($hookKey, $hookVersion, '', $marker, '', $correlationId, 'Undeclared task marker.'),
                    $hookKey,
                    $hookVersion,
                    '',
                    $marker,
                    '',
                    $correlationId,
                );
            }
        }
    }

    /**
     * @param  list<string>  $declaredMarkers
     */
    private function normalizeProviderRaw(
        string $raw,
        array $declaredMarkers,
        bool $strictUndeclaredMarkers,
        string $hookKey,
        string $hookVersion,
        ?string $correlationId,
    ): string {
        // UTF-8 BOM
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        $raw = trim($raw);

        // Entire response wrapped in one markdown code fence.
        if (preg_match('/^```(?:\w+)?\s*\n(.*)\n```$/s', $raw, $fence) === 1) {
            $raw = trim((string) $fence[1]);
        }

        if ($declaredMarkers === []) {
            return $raw;
        }

        // Fail closed on undeclared markers before slicing prologue/epilogue.
        if ($strictUndeclaredMarkers) {
            $this->assertNoUndeclaredTaskMarkers($raw, $declaredMarkers, $hookKey, $hookVersion, $correlationId);
        }

        $firstStart = null;
        $lastEnd = null;
        foreach ($declaredMarkers as $marker) {
            if (str_starts_with($marker, '[START_')) {
                $pos = strpos($raw, $marker);
                if ($pos !== false && ($firstStart === null || $pos < $firstStart)) {
                    $firstStart = $pos;
                }
            }
            if (str_starts_with($marker, '[END_')) {
                $pos = strrpos($raw, $marker);
                if ($pos !== false) {
                    $endPos = $pos + strlen($marker);
                    if ($lastEnd === null || $endPos > $lastEnd) {
                        $lastEnd = $endPos;
                    }
                }
            }
        }

        if ($firstStart === null || $lastEnd === null || $lastEnd <= $firstStart) {
            return $raw;
        }

        $prologue = substr($raw, 0, $firstStart);
        $epilogue = substr($raw, $lastEnd);
        // Only strip short plain-language lead/trail (no nested task markers left).
        if ($this->isDisposableProse($prologue) && $this->isDisposableProse($epilogue)) {
            $raw = substr($raw, $firstStart, $lastEnd - $firstStart);
        }

        return trim($raw);
    }

    private function isDisposableProse(string $chunk): bool
    {
        $trimmed = trim($chunk);
        if ($trimmed === '') {
            return true;
        }
        // Keep fail-closed when chunk still looks like structured task output.
        if (preg_match('/\[(?:START|END)_TASK_/i', $trimmed) === 1) {
            return false;
        }
        // Short lead-in/outro only (model chatter). Longer bodies stay for outside-section check.
        return mb_strlen($trimmed) <= 400;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $consumedSpans
     */
    private function assertNoTextOutsideSections(
        string $raw,
        array $consumedSpans,
        string $hookKey,
        string $hookVersion,
        ?string $correlationId,
    ): void {
        if ($consumedSpans === []) {
            if (trim($raw) !== '') {
                throw new TextOutsideDeclaredSections(
                    $this->contextMessage($hookKey, $hookVersion, '', '', '', $correlationId, 'Text outside declared sections.'),
                    $hookKey,
                    $hookVersion,
                    $correlationId,
                );
            }

            return;
        }

        usort($consumedSpans, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $cursor = 0;
        $outside = '';
        foreach ($consumedSpans as [$start, $end]) {
            if ($start > $cursor) {
                $outside .= substr($raw, $cursor, $start - $cursor);
            }
            $cursor = max($cursor, $end);
        }
        if ($cursor < strlen($raw)) {
            $outside .= substr($raw, $cursor);
        }

        if (trim($outside) !== '') {
            throw new TextOutsideDeclaredSections(
                $this->contextMessage($hookKey, $hookVersion, '', '', '', $correlationId, 'Text outside declared sections.'),
                $hookKey,
                $hookVersion,
                $correlationId,
            );
        }
    }

    /**
     * @param  list<string>  $declaredMarkers
     */
    private function stripAllDeclaredMarkers(string $raw, array $declaredMarkers): string
    {
        $out = $raw;
        foreach ($declaredMarkers as $marker) {
            $out = str_replace($marker, '', $out);
        }

        return trim($out);
    }

    private function contextMessage(
        string $hookKey,
        string $hookVersion,
        string $sectionKey,
        string $startMarker,
        string $endMarker,
        ?string $correlationId,
        string $detail,
    ): string {
        $parts = [
            $hookKey !== '' ? "{$hookKey}@".($hookVersion !== '' ? $hookVersion : '?') : null,
            $sectionKey !== '' ? "section={$sectionKey}" : null,
            $startMarker !== '' ? "start={$startMarker}" : null,
            $endMarker !== '' ? "end={$endMarker}" : null,
            $correlationId !== null && $correlationId !== '' ? "correlation_id={$correlationId}" : null,
            $detail,
        ];

        return implode(' — ', array_values(array_filter($parts)));
    }
}
