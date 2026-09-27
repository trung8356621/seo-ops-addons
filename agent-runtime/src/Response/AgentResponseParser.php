<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Response;

use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalBundle;

final class AgentResponseParser
{
    public function parse(string $raw, RetrievalBundle $bundle): AgentResponse
    {
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
        $blocks = [];
        foreach ($blocksRaw as $block) {
            if (! is_array($block)) {
                throw new AgentResponseRejected('Agent response block is malformed.');
            }
            $blocks[] = $this->block($block, $evidence);
        }

        return new AgentResponse(
            $message,
            $blocks,
            $this->actions(is_array($decoded['actions'] ?? null) ? $decoded['actions'] : []),
            array_map(
                static fn ($source): array => $source->toArray(),
                $bundle->sources,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function block(array $block, EvidenceNumberIndex $evidence): array
    {
        $type = (string) ($block['type'] ?? '');

        return match ($type) {
            'markdown', 'text' => $this->textBlock($block),
            'warning' => [
                'type' => 'warning',
                'text' => $this->requiredText($block),
            ],
            'chart' => $this->chartBlock($block, $evidence),
            'table' => $this->tableBlock($block, $evidence),
            default => throw new AgentResponseRejected('Agent response block type is not supported.'),
        };
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function textBlock(array $block): array
    {
        return [
            'type' => 'markdown',
            'text' => $this->requiredText($block),
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
     * @return array<string, mixed>
     */
    private function tableBlock(array $block, EvidenceNumberIndex $evidence): array
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
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new AgentResponseRejected('Table row is malformed.');
            }
            $clean = [];
            foreach ($columnOut as $column) {
                $value = $row[$column['key']] ?? null;
                if (is_int($value) || is_float($value)) {
                    if (! $evidence->contains($value)) {
                        throw new AgentResponseRejected('Table value is not present in retrieval evidence.');
                    }
                } elseif ($value !== null && ! is_string($value) && ! is_bool($value)) {
                    throw new AgentResponseRejected('Table cell is malformed.');
                }
                $clean[$column['key']] = $value;
            }
            $rowOut[] = $clean;
        }

        return [
            'type' => 'table',
            'title' => trim((string) ($block['title'] ?? '')),
            'columns' => $columnOut,
            'rows' => $rowOut,
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
        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
        if (! is_array($decoded)) {
            throw new AgentResponseRejected('Agent response JSON is invalid.');
        }

        return $decoded;
    }
}
