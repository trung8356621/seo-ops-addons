<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

/**
 * Parse structured Topical Map AI audit JSON.
 */
final class TopicalMapAuditResultParser
{
    /**
     * @return array{ok: bool, message: string, payload: array<string, mixed>|null}
     */
    public function parse(mixed $value): array
    {
        $payload = $this->decode($value);
        if ($payload === null) {
            return ['ok' => false, 'message' => 'Invalid audit JSON.', 'payload' => null];
        }

        $summary = trim((string) ($payload['summary'] ?? ''));
        $findings = is_array($payload['findings'] ?? null) ? array_values($payload['findings']) : [];
        $opportunities = is_array($payload['opportunities'] ?? null) ? array_values($payload['opportunities']) : [];
        $actions = is_array($payload['recommended_actions'] ?? null) ? array_values($payload['recommended_actions']) : [];

        if ($summary === '' && $findings === [] && $opportunities === [] && $actions === []) {
            return ['ok' => false, 'message' => 'Audit payload is empty.', 'payload' => null];
        }

        $normalizedFindings = [];
        foreach ($findings as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalizedFindings[] = [
                'type' => trim((string) ($row['type'] ?? 'observation')),
                'severity' => $this->normalizeSeverity((string) ($row['severity'] ?? 'medium')),
                'topic_ref' => trim((string) ($row['topic_ref'] ?? '')),
                'title' => trim((string) ($row['title'] ?? '')),
                'reason' => trim((string) ($row['reason'] ?? '')),
            ];
        }

        $normalizedOpps = [];
        foreach ($opportunities as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalizedOpps[] = [
                'topic' => trim((string) ($row['topic'] ?? '')),
                'reason' => trim((string) ($row['reason'] ?? '')),
                'suggested_action' => trim((string) ($row['suggested_action'] ?? '')),
            ];
        }

        $normalizedActions = [];
        foreach ($actions as $row) {
            if (is_string($row)) {
                $text = trim($row);
                if ($text !== '') {
                    $normalizedActions[] = $text;
                }

                continue;
            }
            if (is_array($row)) {
                $text = trim((string) ($row['action'] ?? $row['title'] ?? $row['text'] ?? ''));
                if ($text !== '') {
                    $normalizedActions[] = $text;
                }
            }
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

    private function normalizeSeverity(string $raw): string
    {
        $raw = strtolower(trim($raw));

        return in_array($raw, ['low', 'medium', 'high'], true) ? $raw : 'medium';
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
