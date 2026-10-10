<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Model;

use Omnichannel\Addons\AgentRuntime\Retrieval\RetrievalBundle;

/**
 * Compacts SEO Audit samples before the Answer Model sees them.
 * Counts and totals are copied from retrieval, never recalculated.
 */
final class AnswerEvidenceProjector
{
    public function __construct(private readonly AgentModelEvidenceSanitizer $sanitizer = new AgentModelEvidenceSanitizer()) {}

    /** @return array{bundle: array<string, mixed>, analysis_task: list<string>} */
    public function project(RetrievalBundle $bundle, string $message): array
    {
        $payload = $this->sanitizer->sanitize($bundle);
        $hasTopics = false;
        $compacted = false;
        foreach ($payload['sources'] as &$source) {
            if (! is_array($source) || ! is_array($source['data'] ?? null)) {
                continue;
            }
            if (($source['name'] ?? '') === 'topics' || isset($source['data']['topics'])) {
                $hasTopics = true;
                $source['data'] = $this->compactTopics($source['data']);
            }
            $compact = $this->compactAudit($source['data']);
            if ($compact === null) {
                continue;
            }
            $source['data'] = $compact;
            $compacted = true;
        }
        unset($source);

        $tasks = [];
        if ($compacted && $this->isImprovement($message)) {
            $tasks = $this->improvementTask($hasTopics);
        }

        return [
            'bundle' => $payload,
            'analysis_task' => $tasks,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function compactTopics(array $data): array
    {
        $topics = is_array($data['topics'] ?? null) ? $data['topics'] : [];
        $compactedTopics = [];
        foreach (array_slice($topics, 0, 10) as $topic) {
            if (! is_array($topic)) {
                continue;
            }
            $item = [
                'name' => trim((string) ($topic['name'] ?? '')),
                'coverage' => trim((string) ($topic['coverage'] ?? '')),
                'mcp_percent' => $topic['mcp_percent'] ?? null,
                'article_count' => $topic['article_count'] ?? null,
                'topic_ref' => trim((string) ($topic['topic_ref'] ?? '')),
            ];
            if ($item['topic_ref'] === '') {
                unset($item['topic_ref']);
            }
            $compactedTopics[] = $item;
        }

        return [
            'dataset_scope' => 'topic_coverage_sample',
            'summary' => $data['summary'] ?? null,
            'topics' => $compactedTopics,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function compactAudit(array $data): ?array
    {
        if (! is_array($data['items'] ?? null) || ! is_numeric($data['total'] ?? null)) {
            return null;
        }
        $items = array_values(array_filter($data['items'], 'is_array'));
        if ($items === [] || ! $this->looksLikeAudit($items[0])) {
            return null;
        }

        $examples = [];
        foreach (array_slice($items, 0, 8) as $item) {
            $example = [
                'title' => trim((string) ($item['title'] ?? '')),
                'seo_score' => is_numeric($item['seo_score'] ?? null) ? $item['seo_score'] : null,
                'focus_keyword' => trim((string) ($item['focus_keyword'] ?? '')),
                'reason_labels' => array_values(array_filter(array_map(
                    static fn (mixed $reason): string => trim((string) $reason),
                    is_array($item['reason_labels'] ?? null) ? $item['reason_labels'] : [],
                ))),
            ];
            if ($example['title'] === '') {
                unset($example['title']);
            }
            if ($example['focus_keyword'] === '') {
                unset($example['focus_keyword']);
            }
            if ($example['reason_labels'] === []) {
                unset($example['reason_labels']);
            }
            $examples[] = $example;
        }

        $total = (int) $data['total'];
        $sampleSize = count($items);
        $complete = $sampleSize === $total;

        return [
            'dataset_scope' => 'retrieved_article_sample',
            'total' => $total,
            'sample_size' => $sampleSize,
            'sample_covers_total' => $complete,
            'examples' => $examples,
            'uncertainties' => $complete ? [] : [
                'total is the authoritative count of matching records. examples are only the retrieved sample and do not prove that every website article has a low SEO score.',
            ],
        ];
    }

    /** @param  array<string, mixed>  $item */
    private function looksLikeAudit(array $item): bool
    {
        return array_key_exists('seo_score', $item) || array_key_exists('reason_labels', $item);
    }

    private function isImprovement(string $message): bool
    {
        return preg_match('/cải thiện|\bimprov/iu', $message) === 1;
    }

    /** @return list<string> */
    private function improvementTask(bool $hasTopics = false): array
    {
        $tasks = [
            'Prioritize 3 to 5 actionable improvements.',
            'Explain why each matters using only verified evidence.',
            'Name affected groups or articles only where the evidence supports that.',
            'Separate established facts from generated suggestions.',
            'Do not repeat the factual audit report row by row.',
        ];

        if ($hasTopics) {
            $tasks[] = 'Based on weak or undercovered Topics, propose new article candidates (title, target keyword, related topic, search intent).';
            $tasks[] = 'Clearly mark proposed new article candidates as generated/inferred.';
        }

        return $tasks;
    }
}
