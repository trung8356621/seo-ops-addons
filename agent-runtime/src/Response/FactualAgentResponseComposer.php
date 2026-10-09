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

        return $this->verifiedFacts($bundle, $language);
    }

    public function verifiedFacts(RetrievalBundle $bundle, string $language): ?AgentResponse
    {
        $actionable = $this->draftAction($bundle);
        if ($actionable !== null) {
            return $this->actionableTable($actionable, $language, $bundle);
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

    /**
     * @return array{type: string, source: string, site_ref: string}|null
     */
    private function draftAction(RetrievalBundle $bundle): ?array
    {
        foreach ($bundle->sources as $source) {
            if ($source->status !== 'ok' || ($source->data['draft_action'] ?? null) !== 'content_project.draft.intake') {
                continue;
            }
            $type = (string) ($source->data['draft_item_type'] ?? '');
            if (! in_array($type, ['new', 'rewrite', 'improve'], true) || ! is_array($source->data['items'] ?? null)) {
                return null;
            }
            $siteRef = (string) ($bundle->scope->siteRef ?? '');
            if ($siteRef === '' || $bundle->scope->siteId === null) {
                return null;
            }

            return [
                'type' => $type,
                'source' => trim((string) ($source->data['draft_source'] ?? 'agent')) ?: 'agent',
                'site_ref' => $siteRef,
            ];
        }

        return null;
    }

    /**
     * @param  array{type: string, source: string, site_ref: string}  $action
     */
    private function actionableTable(array $action, string $language, RetrievalBundle $bundle): AgentResponse
    {
        $items = [];
        foreach ($bundle->sources as $source) {
            if (($source->data['draft_action'] ?? null) === 'content_project.draft.intake' && is_array($source->data['items'] ?? null)) {
                $items = $source->data['items'];
                break;
            }
        }

        $rows = [];
        $number = 1;
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $recommendation = $this->recommendationItem($item, $action);
            $rows[] = [
                'n' => $number,
                'title' => (string) ($item['title'] ?? ''),
                'article_ref' => (string) ($item['article_ref'] ?? ''),
                'focus_keyword' => (string) ($item['focus_keyword'] ?? $item['keyword'] ?? ''),
                'seo_score' => is_int($item['seo_score'] ?? null) || is_float($item['seo_score'] ?? null) ? $item['seo_score'] : null,
                'item' => $recommendation,
            ];
            $number++;
        }

        $count = count($rows);
        $message = $language === 'vi'
            ? 'Có '.$count.' mục có thể đưa vào Draft.'
            : $count.' items can be added to Draft.';
        $columns = [
            ['key' => 'n', 'label' => '#'],
            ['key' => 'title', 'label' => 'Title'],
            ['key' => 'article_ref', 'label' => 'Article'],
            ['key' => 'focus_keyword', 'label' => 'Keyword'],
            ['key' => 'seo_score', 'label' => 'SEO score'],
        ];

        return new AgentResponse(
            $message,
            [[
                'type' => 'table',
                'title' => '',
                'actionable' => [
                    'action' => 'content_project.draft.intake',
                    'site_ref' => $action['site_ref'],
                ],
                'columns' => $columns,
                'rows' => $rows,
            ]],
            [],
            array_map(static fn (RetrievalSource $source): array => $source->toArray(), $bundle->sources),
        );
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array{type: string, source: string, site_ref: string}  $action
     * @return array<string, mixed>|null
     */
    private function recommendationItem(array $item, array $action): ?array
    {
        $type = $action['type'];
        $articleRef = trim((string) ($item['article_ref'] ?? ''));
        $title = trim((string) ($item['title'] ?? ''));
        $keyword = trim((string) ($item['focus_keyword'] ?? $item['keyword'] ?? ''));
        $reasons = array_values(array_filter(array_map(
            static fn (mixed $reason): string => trim((string) $reason),
            is_array($item['reason_labels'] ?? null) ? $item['reason_labels'] : [],
        )));
        if (in_array($type, ['rewrite', 'improve'], true) && preg_match('/^article:\d+$/', $articleRef) !== 1) {
            return null;
        }
        if ($type === 'new' && $title === '' && $keyword === '') {
            return null;
        }

        $identity = $articleRef !== '' ? $articleRef : ($keyword !== '' ? 'keyword:'.$keyword : 'title:'.$title);

        return [
            'id' => $identity,
            'type' => $type,
            'article_ref' => $articleRef !== '' ? $articleRef : null,
            'title' => $title,
            'keyword' => $keyword,
            'reasons' => $reasons,
            'source' => [
                'type' => $action['source'],
                'ref' => $articleRef !== '' ? $articleRef : $identity,
                'reason' => $reasons === [] ? null : implode('; ', $reasons),
            ],
        ];
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
