<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\Content\Support\SystemDateTime;
use Carbon\Carbon;

/**
 * Single parser for legacy pipe-delimited queue-health cache strings.
 *
 * Storage stays machine-readable (ISO + metadata). Presentation must not explode('|') itself.
 *
 * @phpstan-type Parsed array{
 *     occurred_at: Carbon|null,
 *     count: int|null,
 *     due: int|null,
 *     connection_id: int|null,
 *     reason: string|null,
 *     flags: list<string>,
 *     message: string|null,
 *     raw: string|null,
 *     malformed: bool
 * }
 */
final class OperationalStatusParser
{
    /** @var list<string> */
    public const KNOWN_REASON_CODES = [
        'no_progress',
        'unknown',
        'active_publish',
        'active_lease',
        'stale_claim',
        'awaiting_worker',
        'idempotent_replay',
        'stale_operation',
        'lock_busy',
        'invalid_status',
        'dispatch_failed',
        'claimed',
        'not_due',
        'attempts_exhausted',
        'missing_article',
        'missing_connection',
        'connection_failed',
        'failed',
        'timeout',
        'processing',
        'retrying',
        'stale',
        'waiting',
        'success',
        'not_found',
        'other',
    ];

    private const INTERNAL_ID_KEYS = [
        'project_id',
        'article_id',
        'run_id',
        'job_id',
        'task_id',
        'site_id',
    ];

    /**
     * @return Parsed
     */
    public static function parse(?string $raw): array
    {
        $empty = self::empty($raw);

        if ($raw === null) {
            return $empty;
        }

        $trimmed = trim($raw);
        if ($trimmed === '' || in_array(strtolower($trimmed), ['-', 'null', 'n/a', 'none'], true)) {
            return $empty;
        }

        $iso = $trimmed;
        $rest = '';
        if (str_contains($trimmed, '|')) {
            [$iso, $rest] = explode('|', $trimmed, 2);
            $iso = trim($iso);
            $rest = trim($rest);
        }

        $occurredAt = SystemDateTime::toUtc($iso);
        $malformed = $occurredAt === null && $iso !== '';

        $meta = self::parseMeta($rest);

        return [
            'occurred_at' => $occurredAt,
            'count' => $meta['count'],
            'due' => $meta['due'],
            'connection_id' => $meta['connection_id'],
            'reason' => $meta['reason'],
            'flags' => $meta['flags'],
            'message' => $meta['message'],
            'raw' => $trimmed,
            'malformed' => $malformed,
        ];
    }

    public static function occurredAt(?string $raw): ?Carbon
    {
        return self::parse($raw)['occurred_at'];
    }

    /**
     * @return Parsed
     */
    private static function empty(?string $raw): array
    {
        return [
            'occurred_at' => null,
            'count' => null,
            'due' => null,
            'connection_id' => null,
            'reason' => null,
            'flags' => [],
            'message' => null,
            'raw' => $raw,
            'malformed' => false,
        ];
    }

    /**
     * @return array{
     *     count: int|null,
     *     due: int|null,
     *     connection_id: int|null,
     *     reason: string|null,
     *     flags: list<string>,
     *     message: string|null
     * }
     */
    private static function parseMeta(string $rest): array
    {
        $count = null;
        $due = null;
        $connectionId = null;
        $reason = null;
        $flags = [];
        $messageParts = [];

        if ($rest === '') {
            return [
                'count' => null,
                'due' => null,
                'connection_id' => null,
                'reason' => null,
                'flags' => [],
                'message' => null,
            ];
        }

        $segments = preg_split('/\|/', $rest) ?: [$rest];
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            $tokens = preg_split('/\s+/', $segment) ?: [];
            $segmentHadStructured = false;
            $unstructured = [];

            foreach ($tokens as $token) {
                $token = trim($token);
                if ($token === '') {
                    continue;
                }

                if (str_contains($token, '=')) {
                    [$key, $value] = explode('=', $token, 2);
                    $key = strtolower(trim($key));
                    $value = trim($value);
                    $segmentHadStructured = true;

                    if ($key === 'count' && is_numeric($value)) {
                        $count = (int) $value;
                        continue;
                    }
                    if ($key === 'due' && is_numeric($value)) {
                        $due = (int) $value;
                        continue;
                    }
                    if ($key === 'connection_id' && is_numeric($value)) {
                        $connectionId = (int) $value;
                        continue;
                    }
                    if ($key === 'reason') {
                        $reason = $value !== '' ? $value : $reason;
                        continue;
                    }
                    if (in_array($key, self::INTERNAL_ID_KEYS, true)) {
                        continue;
                    }

                    $unstructured[] = $token;
                    continue;
                }

                $normalized = strtolower($token);
                if (in_array($normalized, self::KNOWN_REASON_CODES, true)) {
                    $segmentHadStructured = true;
                    $flags[] = $normalized;
                    if ($reason === null && $normalized !== 'no_progress') {
                        $reason = $normalized;
                    }
                    continue;
                }

                $unstructured[] = $token;
            }

            if ($unstructured !== []) {
                $joined = implode(' ', $unstructured);
                if ($segmentHadStructured) {
                    $messageParts[] = $joined;
                } else {
                    $messageParts[] = $segment;
                }
            }
        }

        $flags = array_values(array_unique($flags));
        $message = $messageParts !== [] ? trim(implode(' ', $messageParts)) : null;
        if ($message === '') {
            $message = null;
        }

        return [
            'count' => $count,
            'due' => $due,
            'connection_id' => $connectionId,
            'reason' => $reason,
            'flags' => $flags,
            'message' => $message,
        ];
    }
}
