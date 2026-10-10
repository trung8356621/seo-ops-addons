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
        $compacted = false;
        foreach ($payload['sources'] as &$source) {
            if (! is_array($source) || ! is_array($source['data'] ?? null)) {
                continue;
            }
            $compact = $this->compactAudit($source['data']);
            if ($compact === null) {
                continue;
            }
            $source['data'] = $compact;
            $compacted = true;
        }
        unset($source);

        return [
            'bundle' => $payload,
            'analysis_task' => $compacted && $this->isImprovement($message) ? $this->improvementTask() : [],
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
    private function improvementTask(): array
    {
        return [
            'Prioritize 3 to 5 actionable improvements.',
            'Explain why each matters using only verified evidence.',
            'Name affected groups or articles only where the evidence supports that.',
            'Separate established facts from generated suggestions.',
            'Do not repeat the factual audit report row by row.',
        ];
    }
}
