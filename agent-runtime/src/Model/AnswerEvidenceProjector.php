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
    public function project(RetrievalBundle $bundle, string $message, bool $structured = false): array
    {
        $payload = $this->sanitizer->sanitize($bundle);
        if ($structured) {
            return [
                'bundle' => $payload,
                'analysis_task' => $this->analysisTask($message, $payload, false, false),
            ];
        }
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

        $tasks = $compacted && $this->isImprovement($message) ? $this->improvementTask($hasTopics) : [];

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
                'dna_count' => $topic['dna_count'] ?? null,
                'status' => $topic['status'] ?? null,
                'has_focus_article' => $topic['has_focus_article'] ?? null,
                'dna' => is_array($topic['dna'] ?? null) ? $topic['dna'] : null,
                'focus_articles' => is_array($topic['focus_articles'] ?? null) ? $topic['focus_articles'] : null,
                'relationship_status' => $topic['relationship_status'] ?? null,
            ];
            if ($item['topic_ref'] === '') {
                unset($item['topic_ref']);
            }
            $compactedTopics[] = $item;
        }

        $out = $data;
        $out['dataset_scope'] = isset($data['pagination']['total']) && count($compactedTopics) >= (int) $data['pagination']['total']
            ? 'topic_coverage_complete'
            : 'topic_coverage_sample';
        $out['topics'] = $compactedTopics;

        return $out;
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
                'article_ref' => trim((string) ($item['article_ref'] ?? '')),
                'status' => $item['status'] ?? null,
                'slug' => $item['slug'] ?? null,
                'updated_at' => $item['updated_at'] ?? null,
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

        return array_filter([
            'dataset_scope' => 'retrieved_article_sample',
            'total' => $total,
            'sample_size' => $sampleSize,
            'sample_covers_total' => $complete,
            'examples' => $examples,
            'site_ref' => $data['site_ref'] ?? null,
            'schema' => $data['schema'] ?? null,
            'generated_at' => $data['generated_at'] ?? null,
            'source_updated_at' => $data['source_updated_at'] ?? null,
            'pagination' => $data['pagination'] ?? null,
            'selection' => $data['selection'] ?? null,
            '_context_meta' => $data['_context_meta'] ?? null,
            'uncertainties' => $complete ? [] : [
                'total is the authoritative count of matching records. examples are only the retrieved sample and do not prove that every website article has a low SEO score.',
            ],
        ], static fn (mixed $value): bool => $value !== null);
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

    /** @param array<string, mixed> $payload @return list<array<string, mixed>> */
    private function analysisTask(string $message, array $payload, bool $compacted, bool $hasTopics): array
    {
        $sources = [];
        foreach (($payload['sources'] ?? []) as $source) {
            if (is_array($source) && is_string($source['name'] ?? null)) $sources[] = $source['name'];
        }

        $objective = preg_replace('/\s+/u', ' ', trim($message)) ?: trim($message);
        $constraints = [
            'Use only verified records and explicit relationships from retrieval_bundle.',
            'Treat missing or unlinked relationships as unknown/unlinked, not as proof that matching content does not exist.',
            'Do not infer Google performance, demand, canonical, redirect or HTTP state without a direct source field.',
        ];
        if ($this->isImprovement($message)) $constraints[] = 'Separate evidence-backed observations from proposed actions.';
        if ($hasTopics) $constraints[] = 'Preserve topic_ref and article_ref when relating topics to content.';

        return [[
            'user_objective' => mb_substr($objective, 0, 500),
            'decision_to_support' => preg_match('/(?:nên|ưu tiên|chọn|so sánh|quyết định)/iu', $message) === 1
                ? 'Support the requested comparison or choice and state when evidence is insufficient.'
                : 'Answer the requested factual or entity-scoped question.',
            'scope' => $payload['scope'] ?? null,
            'available_sources' => array_values(array_unique($sources)),
            'constraints' => $constraints,
            'context_projection' => $compacted ? 'projected_without_additional_row_limit' : 'source_shape_preserved',
        ]];
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
