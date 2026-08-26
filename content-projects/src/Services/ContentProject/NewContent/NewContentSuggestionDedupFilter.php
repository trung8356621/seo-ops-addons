<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent;

/**
 * Server-side dedup after AI parse. Deterministic only — no fuzzy semantic engine.
 *
 * Hard blockers:
 * - duplicate fingerprint within the same AI batch
 * - duplicate canonical keyword within the same AI batch (one keyword = one Create)
 * - active Draft Create fingerprint / keyword norm
 * - project-scoped rejected fingerprint
 * - actual content coverage norms (published / GSC improvement / published titles)
 *
 * KI inventory presence alone is never a hard duplicate.
 *
 * Canonical keyword comparison uses {@see NewContentSuggestionIdentity::normalize()}
 * (case/whitespace/punctuation-insensitive) — not bare KI phrase identity.
 *
 * @phpstan-import-type Candidate from NewContentSuggestionParser
 */
final class NewContentSuggestionDedupFilter
{
    private const SOURCE_SIGNALS = [
        'keyword_gap',
        'cluster_gap',
        'mcp_signal',
        'related_topic',
        'manual_focus',
    ];

    public const STATUS_ADDED = 'added';

    public const STATUS_DUPLICATE_IN_BATCH = 'duplicate_in_batch';

    public const STATUS_DUPLICATE_IN_BATCH_KEYWORD = 'duplicate_in_batch_keyword';

    public const STATUS_DUPLICATE_DRAFT = 'duplicate_draft';

    public const STATUS_DUPLICATE_COVERED_CONTENT = 'duplicate_covered_content';

    public const STATUS_PROJECT_REJECTED = 'project_rejected';

    /** @deprecated historical / aggregate alias — prefer specific duplicate_* statuses */
    public const STATUS_DUPLICATE_SKIPPED = 'duplicate_skipped';

    public const STATUS_REJECTED_SKIPPED = 'rejected_skipped';

    /**
     * @param  list<Candidate>  $candidates
     * @param  array<string, true>  $plannedFingerprints
     * @param  array<string, true>  $rejectedFingerprints
     * @param  array<string, true>  $coveredKeywordNorms  Content-covered norms only (not bare KI inventory)
     * @param  array<string, true>  $plannedKeywordNorms  Active Draft Create keyword norms
     * @return array{
     *   accepted: list<Candidate>,
     *   duplicate_skipped: int,
     *   rejected_skipped: int,
     *   existing_skipped: int,
     *   duplicate_breakdown: array{in_batch: int, in_batch_keyword: int, active_draft: int, covered_content: int},
     *   results: list<array{keyword: string, title: string, fingerprint: string, status: string, suggestion_reason?: string, source_signal?: string}>
     * }
     */
    public function filter(
        array $candidates,
        array $plannedFingerprints,
        array $rejectedFingerprints,
        array $coveredKeywordNorms,
        array $plannedKeywordNorms = [],
    ): array {
        $accepted = [];
        $rejected = 0;
        $results = [];
        $batchFingerprints = [];
        $batchKeywords = [];
        $breakdown = [
            'in_batch' => 0,
            'in_batch_keyword' => 0,
            'active_draft' => 0,
            'covered_content' => 0,
        ];

        foreach ($candidates as $candidate) {
            $fp = (string) ($candidate['fingerprint'] ?? '');
            $keyword = (string) ($candidate['keyword'] ?? '');
            $title = (string) ($candidate['title'] ?? '');
            $keywordNorm = NewContentSuggestionIdentity::normalize($keyword);
            $reason = (string) ($candidate['suggestion_reason'] ?? '');
            $signal = (string) ($candidate['source_signal'] ?? '');
            if (! in_array($signal, self::SOURCE_SIGNALS, true)) {
                $signal = '';
            }

            if ($fp === '' || isset($batchFingerprints[$fp])) {
                $breakdown['in_batch']++;
                $results[] = $this->resultRow($keyword, $title, $fp, self::STATUS_DUPLICATE_IN_BATCH, $reason, $signal);

                continue;
            }

            // One canonical keyword → one Create suggestion (first valid wins).
            if ($keywordNorm !== '' && isset($batchKeywords[$keywordNorm])) {
                $breakdown['in_batch_keyword']++;
                $results[] = $this->resultRow($keyword, $title, $fp, self::STATUS_DUPLICATE_IN_BATCH_KEYWORD, $reason, $signal);

                continue;
            }

            if (isset($plannedFingerprints[$fp])
                || ($keywordNorm !== '' && isset($plannedKeywordNorms[$keywordNorm]))
            ) {
                $breakdown['active_draft']++;
                $results[] = $this->resultRow($keyword, $title, $fp, self::STATUS_DUPLICATE_DRAFT, $reason, $signal);

                continue;
            }

            if (isset($rejectedFingerprints[$fp])) {
                $rejected++;
                $results[] = $this->resultRow($keyword, $title, $fp, self::STATUS_PROJECT_REJECTED, $reason, $signal);

                continue;
            }

            if ($keywordNorm !== '' && isset($coveredKeywordNorms[$keywordNorm])) {
                $breakdown['covered_content']++;
                $results[] = $this->resultRow($keyword, $title, $fp, self::STATUS_DUPLICATE_COVERED_CONTENT, $reason, $signal);

                continue;
            }

            $batchFingerprints[$fp] = true;
            if ($keywordNorm !== '') {
                $batchKeywords[$keywordNorm] = true;
            }
            $accepted[] = $candidate;
            $results[] = $this->resultRow($keyword, $title, $fp, self::STATUS_ADDED, $reason, $signal);
        }

        $duplicateSkipped = $breakdown['in_batch']
            + $breakdown['in_batch_keyword']
            + $breakdown['active_draft']
            + $breakdown['covered_content'];

        return [
            'accepted' => $accepted,
            'duplicate_skipped' => $duplicateSkipped,
            'rejected_skipped' => $rejected,
            'existing_skipped' => $breakdown['covered_content'],
            'duplicate_breakdown' => $breakdown,
            'results' => $results,
        ];
    }

    /**
     * @return array{keyword: string, title: string, fingerprint: string, status: string, suggestion_reason?: string, source_signal?: string}
     */
    private function resultRow(
        string $keyword,
        string $title,
        string $fp,
        string $status,
        string $reason,
        string $signal,
    ): array {
        $row = [
            'keyword' => $keyword,
            'title' => $title,
            'fingerprint' => $fp,
            'status' => $status,
        ];
        if ($reason !== '') {
            $row['suggestion_reason'] = $reason;
        }
        if ($signal !== '') {
            $row['source_signal'] = $signal;
        }

        return $row;
    }
}
