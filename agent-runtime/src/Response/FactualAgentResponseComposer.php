<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Response;

use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalBundle;
use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalSource;

/**
 * Renders retrieved facts with the existing response blocks.
 * A table or report label does not call a model.
 */
final class FactualAgentResponseComposer
{
    public function compose(RetrievalBundle $bundle, string $message, string $language): ?AgentResponse
    {
        if ($this->needsAnalysis($message, $bundle)) {
            return null;
        }

        $rows = $this->recordRows($bundle);
        if ($rows !== null) {
            return $rows === []
                ? $this->emptyList($language, $bundle)
                : $this->table($rows, $language, $bundle);
        }

        $fields = $this->singleEntity($bundle);
        if ($fields !== null) {
            return $this->entity($fields, $language, $bundle);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function unresolvedRequirements(RetrievalBundle $bundle, string $message): array
    {
        if (! $this->needsAnalysis($message, $bundle)) {
            return [];
        }

        return [
            'Cross-source analysis is unresolved. Synthesize only from the retrieved facts. Do not select tools, repeat routing, or invent missing measurements. Ask the user when essential information is absent.',
        ];
    }

    private function needsAnalysis(string $message, RetrievalBundle $bundle): bool
    {
        $datasets = 0;
        foreach ($bundle->sources as $source) {
            if ($this->isPolicySource($source)) {
                continue;
            }
            if ($source->status === 'ok' && $source->data !== []) {
                $datasets++;
            }
        }

        return $datasets >= 2
            && preg_match('/phân tích|so sánh|tại sao|vì sao|\banalyze\b|\bcompare\b|\bwhy\b/iu', $message) === 1;
    }

    /**
     * @return list<array<string, scalar|null>>|null
     */
    private function recordRows(RetrievalBundle $bundle): ?array
    {
        foreach ($bundle->sources as $source) {
            if ($this->isPolicySource($source) || $source->status !== 'ok' || ! is_array($source->data['items'] ?? null)) {
                continue;
            }
            $rows = [];
            foreach ($source->data['items'] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $row = [];
                foreach ($item as $key => $value) {
                    if (! is_string($key) || is_array($value) || is_object($value)) {
                        continue;
                    }
                    $row[$key] = is_bool($value) || is_int($value) || is_float($value) || $value === null
                        ? $value
                        : (string) $value;
                }
                if ($row !== []) {
                    $rows[] = $row;
                }
            }

            return $rows;
        }

        return null;
    }

    /**
     * @return array<string, scalar|null>|null
     */
    private function singleEntity(RetrievalBundle $bundle): ?array
    {
        $ok = array_values(array_filter(
            $bundle->sources,
            fn (RetrievalSource $source): bool => ! $this->isPolicySource($source) && $source->status === 'ok' && $source->data !== [],
        ));
        if (count($ok) !== 1 || isset($ok[0]->data['items']) || array_key_exists('available', $ok[0]->data)) {
            return null;
        }

        $fields = [];
        foreach ($ok[0]->data as $key => $value) {
            if (! is_string($key) || is_array($value) || is_object($value)) {
                return null;
            }
            $fields[$key] = is_bool($value) || is_int($value) || is_float($value) || $value === null
                ? $value
                : (string) $value;
        }

        return $fields === [] ? null : $fields;
    }

    /**
     * @param  list<array<string, scalar|null>>  $rows
     */
    private function table(array $rows, string $language, RetrievalBundle $bundle): AgentResponse
    {
        $keys = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                $keys[$key] = true;
            }
        }
        $columns = [];
        foreach (array_keys($keys) as $key) {
            $columns[] = ['key' => $key, 'label' => str_replace('_', ' ', $key)];
        }
        $normalized = [];
        foreach ($rows as $row) {
            $clean = [];
            foreach ($columns as $column) {
                $clean[$column['key']] = $row[$column['key']] ?? null;
            }
            $normalized[] = $clean;
        }

        $count = count($normalized);
        $message = $language === 'vi'
            ? 'Có '.$count.' bản ghi phù hợp.'
            : $count.' matching records.';

        return new AgentResponse(
            $message,
            [['type' => 'table', 'title' => '', 'columns' => $columns, 'rows' => $normalized]],
            [],
            array_map(static fn (RetrievalSource $source): array => $source->toArray(), $bundle->sources),
        );
    }

    /**
     * @param  array<string, scalar|null>  $fields
     */
    private function entity(array $fields, string $language, RetrievalBundle $bundle): AgentResponse
    {
        $lines = [];
        foreach ($fields as $key => $value) {
            $shown = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $lines[] = '- '.str_replace('_', ' ', $key).': '.$shown;
        }
        $message = $language === 'vi' ? 'Thông tin đã truy xuất:' : 'Retrieved fields:';
        $text = $message."\n".implode("\n", $lines);

        return new AgentResponse(
            $message,
            [['type' => 'markdown', 'text' => $text]],
            [],
            array_map(static fn (RetrievalSource $source): array => $source->toArray(), $bundle->sources),
        );
    }

    private function isPolicySource(RetrievalSource $source): bool
    {
        return str_ends_with($source->name, '_policy');
    }

    private function emptyList(string $language, RetrievalBundle $bundle): AgentResponse
    {
        $message = $language === 'vi'
            ? 'Không có bản ghi phù hợp với yêu cầu.'
            : 'No matching records were found.';

        return new AgentResponse(
            $message,
            [['type' => 'markdown', 'text' => $message]],
            [],
            array_map(static fn (RetrievalSource $source): array => $source->toArray(), $bundle->sources),
        );
    }
}
