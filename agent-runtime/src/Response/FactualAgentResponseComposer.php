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

        return $this->verifiedFacts($bundle, $language, $message);
    }

    public function verifiedFacts(RetrievalBundle $bundle, string $language, string $message = ''): ?AgentResponse
    {
        $isImprovement = $this->isImprovementAnalysis($message);
        $isExplicitDraft = $this->isExplicitDraftRequest($message);

        if (! $isImprovement || $isExplicitDraft) {
            $actionable = $this->draftAction($bundle);
            if ($actionable !== null) {
                return $this->actionableTable($actionable, $language, $bundle);
            }
        }

        if ($isImprovement && ! $isExplicitDraft) {
            $summary = $this->auditImprovementSummary($bundle, $language);
            if ($summary !== null) {
                return $summary;
            }
        }

        $coverage = $this->coverageStatistics($bundle, $message, $language);
        if ($coverage !== null) {
            return $coverage;
        }

        $rows = $this->recordRows($bundle);
        if ($rows !== null) {
            return $rows === []
                ? $this->emptyList($language, $bundle)
                : $this->present($this->table($rows, $language, $bundle), $bundle);
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
            if ($this->isPolicySource($source) || $source->status !== 'ok') {
                continue;
            }
            $records = is_array($source->data['items'] ?? null)
                ? $source->data['items']
                : (is_array($source->data['topics'] ?? null) ? $source->data['topics'] : null);
            if ($records === null) {
                continue;
            }
            $rows = [];
            foreach ($records as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $row = $this->projectRow($item);
                if ($row !== []) {
                    $rows[] = $row;
                }
            }

            return $rows;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, scalar|null>
     */
    private function projectRow(array $item): array
    {
        $topic = array_key_exists('coverage', $item) && array_key_exists('name', $item);
        $allow = ['name', 'coverage', 'mcp_percent', 'article_count', 'dna_count', 'has_focus_article', 'status'];
        $deny = ['detail_href', 'ui_href', 'coverage_href', 'coverage_links'];
        $row = [];
        foreach ($item as $key => $value) {
            if (! is_string($key) || is_array($value) || is_object($value) || in_array($key, $deny, true)) {
                continue;
            }
            if ($topic && ! in_array($key, $allow, true)) {
                continue;
            }
            if (is_string($value) && $this->isCredentialUrl($value)) {
                continue;
            }
            $row[$key] = is_bool($value) || is_int($value) || is_float($value) || $value === null
                ? $value
                : (string) $value;
        }

        return $row;
    }

    private function coverageStatistics(RetrievalBundle $bundle, string $message, string $language): ?AgentResponse
    {
        if (preg_match('/thống kê|số chủ đề|\bstatistics\b|strong.{0,40}medium.{0,40}weak/iu', $message) !== 1) {
            return null;
        }

        foreach ($bundle->sources as $source) {
            if ($this->isPolicySource($source) || $source->status !== 'ok') {
                continue;
            }
            $counts = $source->data['summary']['coverage_counts'] ?? null;
            $hasTopics = is_array($source->data['topics'] ?? null) || is_array($counts);
            if (! $hasTopics) {
                continue;
            }
            if (is_array($counts) && ($counts['complete'] ?? false) === true
                && is_numeric($counts['strong'] ?? null)
                && is_numeric($counts['medium'] ?? null)
                && is_numeric($counts['weak'] ?? null)
                && is_numeric($counts['counted'] ?? null)) {
                return $this->present($this->coverageReport($source->data, $language, $bundle), $bundle);
            }

            $pageCount = is_array($source->data['topics'] ?? null) ? count($source->data['topics']) : 0;
            $total = is_array($source->data['pagination'] ?? null) ? ($source->data['pagination']['total'] ?? null) : null;

            return $this->partialCoverage($language, $bundle, $pageCount, is_numeric($total) ? (int) $total : null);
        }

        return null;
    }

    /** @param  array<string, mixed>  $data */
    private function coverageReport(array $data, string $language, RetrievalBundle $bundle): AgentResponse
    {
        $counts = is_array($data['summary']['coverage_counts'] ?? null) ? $data['summary']['coverage_counts'] : [];
        $links = is_array($data['summary']['coverage_links'] ?? null) ? $data['summary']['coverage_links'] : [];
        $lines = [];
        foreach (['strong' => 'Strong', 'medium' => 'Medium', 'weak' => 'Weak'] as $level => $label) {
            $lines[] = $this->coverageLine($label, (int) $counts[$level], $links[$level] ?? null);
        }
        if ((int) ($counts['other'] ?? 0) > 0) {
            $lines[] = ($language === 'vi' ? 'Khác' : 'Other').': '.(int) $counts['other'];
        }
        $counted = (int) $counts['counted'];
        $siteTotal = is_numeric($data['summary']['topic_count'] ?? null) ? (int) $data['summary']['topic_count'] : null;
        $lines[] = ($language === 'vi' ? 'Tổng số chủ đề đã đếm' : 'Counted topics').': '.$counted;
        if ($siteTotal !== null && $siteTotal !== $counted) {
            $lines[] = $language === 'vi'
                ? 'Số liệu tính trên '.$counted.' topic sau bộ lọc. Landscape chưa lọc có '.$siteTotal.' topic.'
                : 'Counts cover '.$counted.' filtered topics. The unfiltered landscape has '.$siteTotal.' topics.';
        } else {
            $lines[] = $language === 'vi'
                ? 'Số liệu tính trên toàn bộ topic của website trong landscape hiện tại.'
                : 'Counts cover the complete current website landscape.';
        }
        $text = implode("\n", $lines);
        $message = $language === 'vi' ? 'Thống kê độ bao phủ chủ đề.' : 'Topic coverage statistics.';

        return new AgentResponse(
            $message,
            [['type' => 'markdown', 'text' => $text]],
            [],
            array_map(static fn (RetrievalSource $source): array => $source->toArray(), $bundle->sources),
        );
    }

    private function coverageLine(string $label, int $count, mixed $href): string
    {
        if (is_string($href) && filter_var($href, FILTER_VALIDATE_URL) !== false && ! $this->isCredentialUrl($href)) {
            return '['.$label.']('.$href.'): '.$count;
        }

        return $label.': '.$count;
    }

    private function partialCoverage(string $language, RetrievalBundle $bundle, int $pageCount, ?int $total): AgentResponse
    {
        $message = $language === 'vi'
            ? 'Chỉ có '.$pageCount.' topic trong trang dữ liệu hiện tại'
                .($total !== null ? ' của '.$total.' topic khớp bộ lọc' : '')
                .'. Không dùng số dòng của trang này làm thống kê toàn website.'
            : 'Only '.$pageCount.' topics are in the current page'
                .($total !== null ? ' of '.$total.' matching topics' : '')
                .'. This page count is not a website-wide statistic.';

        return new AgentResponse(
            $message,
            [['type' => 'markdown', 'text' => $message]],
            [],
            array_map(static fn (RetrievalSource $source): array => $source->toArray(), $bundle->sources),
        );
    }

    private function present(AgentResponse $response, RetrievalBundle $bundle): AgentResponse
    {
        return AgentEntityPresentationIndex::fromBundle($bundle)->decorate($response);
    }

    private function listingScope(RetrievalBundle $bundle, int $count, string $language): string
    {
        foreach ($bundle->sources as $source) {
            if (! is_array($source->data['topics'] ?? null)) {
                continue;
            }
            $total = $source->data['pagination']['total'] ?? null;
            if (is_numeric($total) && (int) $total > $count) {
                return $language === 'vi'
                    ? 'Bảng này là trang hiện tại, không phải toàn bộ '.(int) $total.' topic.'
                    : 'This table is the current page, not all '.(int) $total.' topics.';
            }
        }

        return '';
    }

    private function isCredentialUrl(string $value): bool
    {
        return str_contains($value, 'access_tmp') || str_contains($value, '/api/v1/access/');
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
        $scope = $this->listingScope($bundle, $count, $language);
        if ($scope !== '') {
            $message .= ' '.$scope;
        }

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
            if ($recommendation === null) {
                continue;
            }
            $measured = $item['quality_score'] ?? $item['seo_score'] ?? null;
            $rows[] = [
                'n' => $number,
                'title' => (string) ($item['title'] ?? ''),
                'article_ref' => (string) ($recommendation['article_ref'] ?? ''),
                'focus_keyword' => (string) ($item['focus_keyword'] ?? $item['keyword'] ?? ''),
                'seo_score' => is_int($measured) || is_float($measured) ? $measured : null,
                'item' => $recommendation,
            ];
            $number++;
        }

        if ($rows === []) {
            return $this->emptyList($language, $bundle);
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
        if (in_array($type, ['rewrite', 'improve'], true) && ($item['system_point'] ?? null) !== null) {
            return null;
        }
        if (in_array($type, ['rewrite', 'improve'], true) && array_key_exists('rankable', $item) && $item['rankable'] !== true) {
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

    public function isImprovementAnalysis(string $message): bool
    {
        return preg_match('/cải thiện|đề xuất|tối ưu|hướng dẫn|\bimprove\b|\brecommend\b|\boptimization\b/iu', $message) === 1;
    }

    public function isExplicitDraftRequest(string $message): bool
    {
        return preg_match('/(?:đưa|cho|lập|tạo).{0,20}draft|\bdraft\b/iu', $message) === 1;
    }

    public function auditImprovementSummary(RetrievalBundle $bundle, string $language): ?AgentResponse
    {
        $auditSource = null;
        foreach ($bundle->sources as $source) {
            if ($source->status === 'ok' && is_array($source->data['items'] ?? null) && is_numeric($source->data['total'] ?? null)) {
                $items = $source->data['items'];
                if ($items !== [] && (isset($items[0]['seo_score']) || isset($items[0]['reason_labels']))) {
                    $auditSource = $source;
                    break;
                }
            }
        }
        if ($auditSource === null) {
            return null;
        }

        $items = array_values(array_filter($auditSource->data['items'], 'is_array'));
        $total = (int) $auditSource->data['total'];
        $sample = array_slice($items, 0, 8);
        $rows = [];
        $i = 1;
        foreach ($sample as $item) {
            $score = $item['seo_score'] ?? $item['quality_score'] ?? null;
            $reasons = is_array($item['reason_labels'] ?? null) ? implode(', ', $item['reason_labels']) : '';
            $rows[] = [
                'n' => $i++,
                'title' => (string) ($item['title'] ?? ''),
                'focus_keyword' => (string) ($item['focus_keyword'] ?? $item['keyword'] ?? ''),
                'seo_score' => is_numeric($score) ? $score : null,
                'issues' => $reasons,
            ];
        }

        $columns = [
            ['key' => 'n', 'label' => '#'],
            ['key' => 'title', 'label' => $language === 'vi' ? 'Tiêu đề' : 'Title'],
            ['key' => 'focus_keyword', 'label' => $language === 'vi' ? 'Từ khóa chính' : 'Focus Keyword'],
            ['key' => 'seo_score', 'label' => 'SEO Score'],
            ['key' => 'issues', 'label' => $language === 'vi' ? 'Vấn đề chính' : 'Main Issues'],
        ];

        $sampleCount = count($rows);
        $message = $language === 'vi'
            ? "Tổng số bài viết cần cải thiện SEO là {$total}. Dưới đây là mẫu {$sampleCount} bài viết có điểm SEO thấp nhất đã truy xuất:"
            : "Total articles needing SEO improvement: {$total}. Below is a sample of the {$sampleCount} lowest-scoring articles retrieved:";

        $sectionHeader = $language === 'vi'
            ? "### A. Bài viết hiện có cần cải thiện\n{$message}"
            : "### A. Existing Articles Needing Improvement\n{$message}";

        $blocks = [
            ['type' => 'markdown', 'text' => $sectionHeader],
            [
                'type' => 'table',
                'columns' => $columns,
                'rows' => $rows,
            ],
        ];

        return new AgentResponse(
            $message,
            $blocks,
            [],
            array_map(static fn (RetrievalSource $source): array => $source->toArray(), $bundle->sources),
        );
    }
}
