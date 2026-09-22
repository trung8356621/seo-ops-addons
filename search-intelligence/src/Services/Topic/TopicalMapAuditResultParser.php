<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

/**
 * Deterministic validation for Topical Map AI audit JSON.
 */
final class TopicalMapAuditResultParser
{
    /**
     * @param  list<string>  $allowedTopicRefs  Exact topic_ref values supplied in prompt input
     * @return array{ok: bool, message: string, payload: array<string, mixed>|null}
     */
    public function parse(mixed $value, array $allowedTopicRefs = []): array
    {
        $payload = $this->decode($value);
        if ($payload === null) {
            return ['ok' => false, 'message' => 'Invalid audit JSON.', 'payload' => null];
        }

        $allowed = [];
        foreach ($allowedTopicRefs as $ref) {
            $ref = trim((string) $ref);
            if ($ref !== '') {
                $allowed[$ref] = true;
            }
        }

        $summary = trim((string) ($payload['summary'] ?? ''));
        $findings = is_array($payload['findings'] ?? null) ? array_values($payload['findings']) : [];
        $opportunities = is_array($payload['opportunities'] ?? null) ? array_values($payload['opportunities']) : [];
        $actions = is_array($payload['recommended_actions'] ?? null) ? array_values($payload['recommended_actions']) : [];

        $normalizedFindings = $this->normalizeFindings($findings, $allowed);
        $normalizedOpps = $this->normalizeOpportunities($opportunities, $allowed);
        $normalizedActions = $this->normalizeActions($actions, $allowed);

        if ($summary === '' && $normalizedFindings === [] && $normalizedOpps === [] && $normalizedActions === []) {
            return ['ok' => false, 'message' => 'Audit payload is empty.', 'payload' => null];
        }

        return [
            'ok' => true,
            'message' => '',
            'payload' => [
                'summary' => $summary,
                'findings' => $normalizedFindings,
                'opportunities' => $normalizedOpps,
                'recommended_actions' => $normalizedActions,
            ],
        ];
    }

    /**
     * @param  list<mixed>  $rows
     * @param  array<string, true>  $allowed
     * @return list<array<string, mixed>>
     */
    private function normalizeFindings(array $rows, array $allowed): array
    {
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = strtolower(trim((string) ($row['type'] ?? '')));
            if (! TopicalMapAuditContracts::isAllowedFindingType($type)) {
                continue;
            }
            $severity = strtolower(trim((string) ($row['severity'] ?? 'medium')));
            if (! TopicalMapAuditContracts::isAllowedSeverity($severity)) {
                $severity = 'medium';
            }
            $topicRef = $this->normalizeTopicRef($row['topic_ref'] ?? null, $allowed);
            $title = trim((string) ($row['title'] ?? ''));
            $observation = trim((string) ($row['observation'] ?? $row['reason'] ?? ''));
            $topicName = trim((string) ($row['topic_name'] ?? ''));
            $evidence = $this->normalizeEvidence($row['evidence'] ?? null);
            if ($title === '' && $observation === '') {
                continue;
            }
            $dedupeKey = mb_strtolower($type.'|'.($topicRef ?? '').'|'.$title.'|'.$observation);
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;
            $out[] = [
                'type' => $type,
                'severity' => $severity,
                'topic_ref' => $topicRef,
                'topic_name' => $topicName,
                'title' => $title !== '' ? $title : $type,
                'observation' => $observation,
                'evidence' => $evidence,
            ];
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $rows
     * @param  array<string, true>  $allowed
     * @return list<array<string, mixed>>
     */
    private function normalizeOpportunities(array $rows, array $allowed): array
    {
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? $row['topic'] ?? ''));
            $reason = trim((string) ($row['reason'] ?? ''));
            $direction = trim((string) ($row['suggested_direction'] ?? $row['suggested_action'] ?? ''));
            $topicName = trim((string) ($row['topic_name'] ?? $row['topic'] ?? ''));
            $topicRef = $this->normalizeTopicRef($row['topic_ref'] ?? null, $allowed);
            if ($title === '' && $reason === '' && $direction === '') {
                continue;
            }
            $dedupeKey = mb_strtolower(($topicRef ?? '').'|'.$title.'|'.$reason);
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;
            $out[] = [
                'topic_ref' => $topicRef,
                'topic_name' => $topicName,
                'title' => $title !== '' ? $title : ($topicName !== '' ? $topicName : 'Opportunity'),
                'reason' => $reason,
                'suggested_direction' => $direction,
            ];
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $rows
     * @param  array<string, true>  $allowed
     * @return list<array<string, mixed>>
     */
    private function normalizeActions(array $rows, array $allowed): array
    {
        $out = [];
        $seen = [];
        $autoPriority = 1;
        foreach ($rows as $row) {
            if (is_string($row)) {
                $text = trim($row);
                if ($text === '') {
                    continue;
                }
                $out[] = [
                    'priority' => $autoPriority++,
                    'action_type' => 'no_action',
                    'topic_ref' => null,
                    'title' => $text,
                    'reason' => '',
                ];

                continue;
            }
            if (! is_array($row)) {
                continue;
            }
            $actionType = strtolower(trim((string) ($row['action_type'] ?? '')));
            if ($actionType === '' || ! TopicalMapAuditContracts::isAllowedActionType($actionType)) {
                $actionType = 'no_action';
            }
            $title = trim((string) ($row['title'] ?? $row['action'] ?? $row['text'] ?? ''));
            $reason = trim((string) ($row['reason'] ?? ''));
            if ($title === '' && $reason === '') {
                continue;
            }
            $priority = $row['priority'] ?? $autoPriority;
            $priority = is_numeric($priority) ? (int) $priority : $autoPriority;
            if ($priority < 1) {
                $priority = $autoPriority;
            }
            $autoPriority = max($autoPriority, $priority + 1);
            $topicRef = $this->normalizeTopicRef($row['topic_ref'] ?? null, $allowed);
            $dedupeKey = mb_strtolower($actionType.'|'.($topicRef ?? '').'|'.$title);
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;
            $out[] = [
                'priority' => $priority,
                'action_type' => $actionType,
                'topic_ref' => $topicRef,
                'title' => $title !== '' ? $title : $actionType,
                'reason' => $reason,
            ];
        }

        usort($out, static fn (array $a, array $b): int => ((int) $a['priority']) <=> ((int) $b['priority']));

        return $out;
    }

    /**
     * @param  array<string, true>  $allowed
     */
    private function normalizeTopicRef(mixed $raw, array $allowed): ?string
    {
        $ref = trim((string) $raw);
        if ($ref === '') {
            return null;
        }
        if (TopicalMapAuditContracts::topicIdFromRef($ref) === null) {
            return null;
        }
        if ($allowed !== [] && ! isset($allowed[$ref])) {
            return null;
        }

        return $ref;
    }

    /**
     * @return list<string>
     */
    private function normalizeEvidence(mixed $raw): array
    {
        if (! is_array($raw)) {
            $text = trim((string) $raw);

            return $text !== '' ? [$text] : [];
        }
        $out = [];
        foreach ($raw as $item) {
            $text = trim((string) $item);
            if ($text !== '') {
                $out[] = $text;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $text = trim($value);
        if ($text === '') {
            return null;
        }
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
            $text = trim($text);
        }
        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : null;
    }
}
