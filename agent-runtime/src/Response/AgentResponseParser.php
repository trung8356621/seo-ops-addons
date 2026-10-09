<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Response;

use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalBundle;

final class AgentResponseParser
{
    /** @var list<string> */
    private array $rejections = [];

    /** @return list<string> */
    public function lastRejections(): array
    {
        return $this->rejections;
    }

    public function parse(string $raw, RetrievalBundle $bundle): AgentResponse
    {
        $this->rejections = [];
        $decoded = $this->decodeObject($raw);
        $message = trim((string) ($decoded['message'] ?? ''));
        if ($message === '') {
            throw new AgentResponseRejected('Agent response is missing message.');
        }

        $blocksRaw = $decoded['blocks'] ?? [];
        if (! is_array($blocksRaw)) {
            throw new AgentResponseRejected('Agent response blocks must be a list.');
        }

        $evidence = EvidenceNumberIndex::fromBundle($bundle);
        $links = EvidenceLinkIndex::fromBundle($bundle);
        $articleRecords = $this->articleRecords($bundle);
        $links->assertMarkdown($message);
        $blocks = [];
        foreach ($blocksRaw as $block) {
            if (! is_array($block)) {
                $this->rejections[] = 'Agent response block is malformed.';
                continue;
            }
            try {
                $blocks[] = $this->block($block, $evidence, $links, $articleRecords);
            } catch (AgentResponseRejected $error) {
                $this->rejections[] = $error->getMessage();
            }
        }

        $response = new AgentResponse(
            $message,
            $blocks,
            $this->actions(is_array($decoded['actions'] ?? null) ? $decoded['actions'] : []),
            array_map(
                static fn ($source): array => $source->toArray(),
                $bundle->sources,
            ),
        );

        return AgentEntityPresentationIndex::fromBundle($bundle)->decorate($response);
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, array<string, mixed>>  $articleRecords
     * @return array<string, mixed>
     */
    private function block(array $block, EvidenceNumberIndex $evidence, EvidenceLinkIndex $links, array $articleRecords): array
    {
        $type = (string) ($block['type'] ?? '');

        return match ($type) {
            'markdown', 'text' => $this->textBlock($block, $links),
            'warning' => [
                'type' => 'warning',
                'text' => $this->requiredText($block),
            ],
            'chart' => $this->chartBlock($block, $evidence),
            'table' => $this->tableBlock($block, $evidence, $articleRecords),
            default => throw new AgentResponseRejected('Agent response block type is not supported.'),
        };
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function textBlock(array $block, EvidenceLinkIndex $links): array
    {
        $text = $this->requiredText($block);
        $links->assertMarkdown($text);

        return [
            'type' => 'markdown',
            'text' => $text,
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function requiredText(array $block): string
    {
        $text = trim((string) ($block['text'] ?? $block['markdown'] ?? ''));
        if ($text === '') {
            throw new AgentResponseRejected('Text block is empty.');
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function chartBlock(array $block, EvidenceNumberIndex $evidence): array
    {
        $chart = (string) ($block['chart'] ?? '');
        if (! in_array($chart, ['line', 'bar'], true)) {
            throw new AgentResponseRejected('Chart block chart type is invalid.');
        }
        $xKey = trim((string) ($block['x_key'] ?? ''));
        $series = $block['series'] ?? null;
        $data = $block['data'] ?? null;
        if ($xKey === '' || ! is_array($series) || $series === [] || ! is_array($data)) {
            throw new AgentResponseRejected('Chart block is malformed.');
        }

        $seriesOut = [];
        foreach ($series as $item) {
            if (! is_array($item)) {
                throw new AgentResponseRejected('Chart series is malformed.');
            }
            $key = trim((string) ($item['key'] ?? ''));
            $label = trim((string) ($item['label'] ?? ''));
            if ($key === '' || $label === '') {
                throw new AgentResponseRejected('Chart series is malformed.');
            }
            $seriesOut[] = ['key' => $key, 'label' => $label];
        }

        $rows = [];
        foreach ($data as $row) {
            if (! is_array($row) || ! array_key_exists($xKey, $row)) {
                throw new AgentResponseRejected('Chart data is malformed.');
            }
            $clean = [$xKey => (string) $row[$xKey]];
            foreach ($seriesOut as $item) {
                $value = $row[$item['key']] ?? null;
                if (! is_int($value) && ! is_float($value)) {
                    throw new AgentResponseRejected('Chart values must be numbers from retrieval evidence.');
                }
                if (! $evidence->contains($value)) {
                    throw new AgentResponseRejected('Chart value is not present in retrieval evidence.');
                }
                $clean[$item['key']] = $value;
            }
            $rows[] = $clean;
        }

        return [
            'type' => 'chart',
            'chart' => $chart,
            'title' => trim((string) ($block['title'] ?? '')),
            'x_key' => $xKey,
            'series' => $seriesOut,
            'data' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, array<string, mixed>>  $articleRecords
     * @return array<string, mixed>
     */
    private function tableBlock(array $block, EvidenceNumberIndex $evidence, array $articleRecords): array
    {
        $columns = $block['columns'] ?? null;
        $rows = $block['rows'] ?? null;
        if (! is_array($columns) || $columns === [] || ! is_array($rows)) {
            throw new AgentResponseRejected('Table block is malformed.');
        }
        $columnOut = [];
        foreach ($columns as $column) {
            if (! is_array($column)) {
                throw new AgentResponseRejected('Table column is malformed.');
            }
            $key = trim((string) ($column['key'] ?? ''));
            $label = trim((string) ($column['label'] ?? ''));
            if ($key === '' || $label === '') {
                throw new AgentResponseRejected('Table column is malformed.');
            }
            $columnOut[] = ['key' => $key, 'label' => $label];
        }
        $rowOut = [];
        $positional = $this->positionalIndexColumns($columnOut, $rows);
        $seenRefs = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new AgentResponseRejected('Table row is malformed.');
            }
            $record = $this->matchedArticle($row, $articleRecords, $seenRefs);
            if ($record !== null) {
                $row = $this->rehydrateArticleRow($row, $record);
            }
            $clean = [];
            foreach ($columnOut as $column) {
                $value = $row[$column['key']] ?? null;
                $rowScore = $record !== null && in_array($column['key'], ['seo_score', 'score', 'quality_score'], true);
                if ($rowScore) {
                    $value = $this->factualScore($record);
                } elseif (is_int($value) || is_float($value)) {
                    if (! isset($positional[$column['key']]) && ! $evidence->contains($value)) {
                        throw new AgentResponseRejected('Table value is not present in retrieval evidence.');
                    }
                } elseif ($value !== null && ! is_string($value) && ! is_bool($value)) {
                    throw new AgentResponseRejected('Table cell is malformed.');
                }
                $clean[$column['key']] = $value;
            }
            if (array_key_exists('item', $row)) {
                $clean['item'] = $this->recommendationItem($row['item'], $record);
            }
            $rowOut[] = $clean;
        }

        $out = [
            'type' => 'table',
            'title' => trim((string) ($block['title'] ?? '')),
            'columns' => $columnOut,
            'rows' => $rowOut,
        ];
        $hasItems = false;
        foreach ($rowOut as $row) {
            if (isset($row['item'])) {
                $hasItems = true;
                break;
            }
        }
        if ($hasItems || array_key_exists('actionable', $block)) {
            if (! $hasItems) {
                throw new AgentResponseRejected('Actionable table has no validated recommendations.');
            }
            $actionable = $block['actionable'] ?? null;
            if (! is_array($actionable) || ($actionable['action'] ?? null) !== 'content_project.draft.intake') {
                throw new AgentResponseRejected('Actionable recommendations require the draft intake action.');
            }
            $siteRef = trim((string) ($actionable['site_ref'] ?? ''));
            if (preg_match('/^site:\d+$/', $siteRef) !== 1) {
                throw new AgentResponseRejected('Actionable table site_ref is invalid.');
            }
            $out['actionable'] = [
                'action' => 'content_project.draft.intake',
                'site_ref' => $siteRef,
            ];
        }

        return $out;
    }

    /**
     * Row indexes such as STT are presentation, not SEO measurements.
     * They are ignored only when every value is that row's 1-based position.
     *
     * @param  list<array{key: string, label: string}>  $columns
     * @param  list<mixed>  $rows
     * @return array<string, true>
     */
    private function positionalIndexColumns(array $columns, array $rows): array
    {
        $positional = [];
        foreach ($columns as $column) {
            if (preg_match('/^(n|no|stt|index|row|row_number|#)$/i', $column['key']) !== 1
                && preg_match('/^(#|stt|no\.?|row)$/i', $column['label']) !== 1) {
                continue;
            }
            $position = 1;
            $matches = $rows !== [];
            foreach ($rows as $row) {
                if (! is_array($row) || ! array_key_exists($column['key'], $row)) {
                    $matches = false;
                    break;
                }
                $value = $row[$column['key']];
                $numeric = is_int($value) || (is_float($value) && floor($value) === $value);
                if (! $numeric || (int) $value !== $position) {
                    $matches = false;
                    break;
                }
                $position++;
            }
            if ($matches) {
                $positional[$column['key']] = true;
            }
        }

        return $positional;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, array<string, mixed>>  $articleRecords
     * @param  array<string, true>  $seenRefs
     * @return array<string, mixed>|null
     */
    private function matchedArticle(array $row, array $articleRecords, array &$seenRefs): ?array
    {
        $item = is_array($row['item'] ?? null) ? $row['item'] : null;
        $type = $item !== null ? trim((string) ($item['type'] ?? '')) : '';
        $itemRef = $item !== null ? trim((string) ($item['article_ref'] ?? '')) : '';
        if (in_array($type, ['rewrite', 'improve'], true) && preg_match('/^article:\d+$/', $itemRef) !== 1) {
            throw new AgentResponseRejected('Existing-article recommendations require article_ref.');
        }
        $rowRef = trim((string) ($row['article_ref'] ?? ''));
        if ($rowRef !== '' && $itemRef !== '' && $rowRef !== $itemRef) {
            throw new AgentResponseRejected('Article ref does not match the recommendation.');
        }
        $ref = $itemRef !== '' ? $itemRef : $rowRef;
        if ($ref === '') {
            return null;
        }
        if (preg_match('/^article:\d+$/', $ref) !== 1 || ! isset($articleRecords[$ref])) {
            throw new AgentResponseRejected('Article ref is not in the authorized retrieval set.');
        }
        if (isset($seenRefs[$ref])) {
            throw new AgentResponseRejected('Duplicate article_ref.');
        }
        $seenRefs[$ref] = true;

        return $articleRecords[$ref];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function rehydrateArticleRow(array $row, array $record): array
    {
        foreach (['title', 'focus_keyword', 'status'] as $key) {
            if (array_key_exists($key, $record) && ! is_array($record[$key]) && ! is_object($record[$key])) {
                $row[$key] = $record[$key] === null ? null : (string) $record[$key];
            }
        }
        $score = $this->factualScore($record);
        $row['seo_score'] = $score;
        $row['quality_score'] = $score;
        if (is_array($row['item'] ?? null)) {
            $row['item']['article_ref'] = (string) ($record['article_ref'] ?? '');
            if (isset($row['title'])) {
                $row['item']['title'] = (string) $row['title'];
            }
            if (array_key_exists('focus_keyword', $row)) {
                $row['item']['keyword'] = (string) $row['focus_keyword'];
            }
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function factualScore(array $record): int|float|null
    {
        if (($record['system_point'] ?? null) !== null) {
            return null;
        }
        if (array_key_exists('rankable', $record) && $record['rankable'] !== true) {
            return null;
        }
        $measured = $record['quality_score'] ?? $record['seo_score'] ?? null;

        return is_int($measured) || is_float($measured) ? $measured : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function articleRecords(RetrievalBundle $bundle): array
    {
        $records = [];
        foreach ($bundle->sources as $source) {
            if ($source->status !== 'ok' || ! is_array($source->data['items'] ?? null)) {
                continue;
            }
            foreach ($source->data['items'] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $ref = trim((string) ($item['article_ref'] ?? ''));
                if (preg_match('/^article:\d+$/', $ref) !== 1 || isset($records[$ref])) {
                    continue;
                }
                $records[$ref] = $item;
            }
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>|null  $record
     * @return array<string, mixed>
     */
    private function recommendationItem(mixed $item, ?array $record = null): array
    {
        if (! is_array($item)) {
            throw new AgentResponseRejected('Recommendation item is malformed.');
        }
        $type = trim((string) ($item['type'] ?? ''));
        if (! in_array($type, ['new', 'rewrite', 'improve'], true)) {
            throw new AgentResponseRejected('Recommendation item type is invalid.');
        }
        $id = trim((string) ($item['id'] ?? ''));
        $articleRef = trim((string) ($item['article_ref'] ?? ''));
        $title = trim((string) ($item['title'] ?? ''));
        $keyword = trim((string) ($item['keyword'] ?? ''));
        if ($id === '') {
            throw new AgentResponseRejected('Recommendation item id is required.');
        }
        if (in_array($type, ['rewrite', 'improve'], true) && preg_match('/^article:\d+$/', $articleRef) !== 1) {
            throw new AgentResponseRejected('Existing-article recommendations require article_ref.');
        }
        if ($type === 'new' && $title === '' && $keyword === '') {
            throw new AgentResponseRejected('New recommendations require a title or keyword.');
        }
        $reasons = [];
        foreach (is_array($item['reasons'] ?? null) ? $item['reasons'] : [] as $reason) {
            $text = trim((string) $reason);
            if ($text !== '') {
                $reasons[] = $text;
            }
        }
        $source = is_array($item['source'] ?? null) ? $item['source'] : [];
        $sourceType = trim((string) ($source['type'] ?? ''));
        if ($sourceType === '') {
            throw new AgentResponseRejected('Recommendation source is required.');
        }

        return [
            'id' => $id,
            'type' => $type,
            'article_ref' => $articleRef !== '' ? $articleRef : null,
            'title' => $title,
            'keyword' => $keyword,
            'reasons' => $reasons,
            'source' => [
                'type' => $sourceType,
                'ref' => trim((string) ($source['ref'] ?? '')) ?: null,
                'reason' => trim((string) ($source['reason'] ?? '')) ?: null,
            ],
        ];
    }

    /**
     * @param  list<mixed>  $actions
     * @return list<array<string, mixed>>
     */
    private function actions(array $actions): array
    {
        $out = [];
        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }
            $name = (string) ($action['action'] ?? $action['type'] ?? '');
            if ($name !== 'content_project.draft.intake') {
                continue;
            }
            $out[] = [
                'action' => 'content_project.draft.intake',
                'status' => 'not_connected',
                'label' => trim((string) ($action['label'] ?? 'Draft intake')),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeObject(string $raw): array
    {
        $raw = trim($raw);
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $raw, $match) === 1) {
            $raw = $match[1];
        }
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) {
            throw new AgentResponseRejected('Agent response is not JSON.');
        }
        $json = substr($raw, $start, $end - $start + 1);
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            $repaired = $this->repairMarkdownEscapes($json);
            if ($repaired !== $json) {
                $decoded = json_decode($repaired, true);
            }
        }
        if (! is_array($decoded)) {
            throw new AgentResponseRejected('Agent response JSON is invalid.');
        }

        return $decoded;
    }

    /**
     * Narrowly strip provider-generated invalid backslash escapes before common Markdown punctuation.
     * Preserves valid JSON escapes (\", \\, \/, \b, \f, \n, \r, \t, \uXXXX) and even counts of preceding backslashes.
     */
    public function repairMarkdownEscapes(string $json): string
    {
        $repaired = preg_replace('/(?<!\\\\)((?:\\\\\\\\)*)\\\\([*_#\\[\\]()~`>+!\\-])/', '$1$2', $json);

        return is_string($repaired) ? $repaired : $json;
    }
}
