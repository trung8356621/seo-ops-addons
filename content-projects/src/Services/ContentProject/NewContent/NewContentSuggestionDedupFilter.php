<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent;

/**
 * Server-side dedup after AI parse. Deterministic only — no fuzzy semantic engine.
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

    /**
     * @param  list<Candidate>  $candidates
     * @param  array<string, true>  $plannedFingerprints
     * @param  array<string, true>  $rejectedFingerprints
     * @param  array<string, true>  $coveredKeywordNorms  Content-covered norms only (not bare KI inventory)
     * @return array{
     *   accepted: list<Candidate>,
     *   duplicate_skipped: int,
     *   rejected_skipped: int,
     *   existing_skipped: int,
     *   results: list<array{keyword: string, title: string, fingerprint: string, status: string, suggestion_reason?: string, source_signal?: string}>
     * }
     */
    public function filter(
        array $candidates,
        array $plannedFingerprints,
        array $rejectedFingerprints,
        array $coveredKeywordNorms,
    ): array {
        $accepted = [];
        $duplicate = 0;
        $rejected = 0;
        $existing = 0;
        $results = [];
        $batch = [];

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

            if ($fp === '' || isset($batch[$fp]) || isset($plannedFingerprints[$fp])) {
                $duplicate++;
                $results[] = $this->resultRow($keyword, $title, $fp, 'duplicate_skipped', $reason, $signal);

                continue;
            }

            if (isset($rejectedFingerprints[$fp])) {
                $rejected++;
                $results[] = $this->resultRow($keyword, $title, $fp, 'rejected_skipped', $reason, $signal);

                continue;
            }

            // Only block when content coverage evidence exists (linked published / planned / title).
            if ($keywordNorm !== '' && isset($coveredKeywordNorms[$keywordNorm])) {
                $existing++;
                $results[] = $this->resultRow($keyword, $title, $fp, 'duplicate_skipped', $reason, $signal);

                continue;
            }

            $batch[$fp] = true;
            $accepted[] = $candidate;
            $results[] = $this->resultRow($keyword, $title, $fp, 'added', $reason, $signal);
        }

        return [
            'accepted' => $accepted,
            'duplicate_skipped' => $duplicate + $existing,
            'rejected_skipped' => $rejected,
            'existing_skipped' => $existing,
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
