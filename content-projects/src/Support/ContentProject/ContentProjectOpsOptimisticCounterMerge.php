<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Merge canonical ops summary with in-flight optimistic counter transitions.
 *
 * Presentation-only. Command accepted ≠ read-model reconciled.
 */
final class ContentProjectOpsOptimisticCounterMerge
{
    /** Grace period before pending transition is dropped without rollback (ms). */
    public const GRACE_MS = 12000;

    /**
     * @param  array<string, int>  $canonical
     * @param  list<array{
     *     operationId?: string,
     *     deltas?: array<string, int>,
     *     reconciled?: bool,
     *     expiresAt?: int,
     * }>  $pending
     * @param  int|null  $nowMs
     * @return array<string, int>
     */
    public static function sumPendingDeltas(array $pending, ?int $nowMs = null): array
    {
        $now = $nowMs ?? (int) floor(microtime(true) * 1000);
        $sum = [];

        foreach ($pending as $transition) {
            if (! is_array($transition)) {
                continue;
            }
            if (($transition['reconciled'] ?? false) === true) {
                continue;
            }
            $expiresAt = (int) ($transition['expiresAt'] ?? 0);
            if ($expiresAt > 0 && $expiresAt < $now) {
                continue;
            }
            $deltas = $transition['deltas'] ?? null;
            if (! is_array($deltas)) {
                continue;
            }
            foreach ($deltas as $key => $delta) {
                $k = (string) $key;
                $sum[$k] = (int) ($sum[$k] ?? 0) + (int) $delta;
            }
        }

        return $sum;
    }

    /**
     * @param  array<string, int>  $canonical
     * @param  list<array<string, mixed>>  $pending
     * @param  int|null  $nowMs
     * @return array<string, int>
     */
    public static function display(array $canonical, array $pending, ?int $nowMs = null): array
    {
        $canonical = self::normalizeCounters($canonical);
        $sum = self::sumPendingDeltas($pending, $nowMs);
        $out = $canonical;
        foreach ($sum as $key => $delta) {
            $out[$key] = max(0, (int) ($canonical[$key] ?? 0) + (int) $delta);
        }

        return $out;
    }

    /**
     * Transition is reflected in canonical when every delta direction is satisfied vs baseline.
     *
     * @param  array{deltas?: array<string, int>, baseline?: array<string, int>}  $transition
     * @param  array<string, int>  $canonical
     */
    public static function isReconciled(array $transition, array $canonical): bool
    {
        $deltas = $transition['deltas'] ?? null;
        $baseline = $transition['baseline'] ?? null;
        if (! is_array($deltas) || $deltas === [] || ! is_array($baseline)) {
            return false;
        }

        $canonical = self::normalizeCounters($canonical);
        $baseline = self::normalizeCounters($baseline);

        foreach ($deltas as $key => $delta) {
            $d = (int) $delta;
            if ($d === 0) {
                continue;
            }
            $k = (string) $key;
            $diff = (int) ($canonical[$k] ?? 0) - (int) ($baseline[$k] ?? 0);
            if ($d > 0 && $diff < $d) {
                return false;
            }
            if ($d < 0 && $diff > $d) {
                return false;
            }
        }

        return true;
    }

    /**
     * Drop reconciled or expired pending transitions after a new canonical snapshot.
     *
     * @param  array<string, int>  $canonical
     * @param  list<array<string, mixed>>  $pending
     * @param  int|null  $nowMs
     * @return list<array<string, mixed>>
     */
    public static function prunePending(array $canonical, array $pending, ?int $nowMs = null): array
    {
        $now = $nowMs ?? (int) floor(microtime(true) * 1000);
        $kept = [];

        foreach ($pending as $transition) {
            if (! is_array($transition)) {
                continue;
            }
            $operationId = trim((string) ($transition['operationId'] ?? ''));
            if ($operationId !== '') {
                foreach ($kept as $existing) {
                    if (($existing['operationId'] ?? '') === $operationId) {
                        continue 2; // idempotent: one row per operationId
                    }
                }
            }
            if (($transition['reconciled'] ?? false) === true) {
                continue;
            }
            if (self::isReconciled($transition, $canonical)) {
                continue;
            }
            $expiresAt = (int) ($transition['expiresAt'] ?? 0);
            if ($expiresAt > 0 && $expiresAt < $now) {
                // Grace expired: absorb into canonical display (no rollback).
                continue;
            }
            $kept[] = $transition;
        }

        return array_values($kept);
    }

    /**
     * @param  array<string, int>  $deltas
     * @param  array<string, int>  $baseline
     * @return array{
     *     operationId: string,
     *     itemId: int,
     *     action: string,
     *     deltas: array<string, int>,
     *     baseline: array<string, int>,
     *     acceptedAt: int,
     *     expiresAt: int,
     *     reconciled: bool,
     * }
     */
    public static function makePending(
        string $operationId,
        int $itemId,
        string $action,
        array $deltas,
        array $baseline,
        ?int $acceptedAtMs = null,
        int $graceMs = self::GRACE_MS,
    ): array {
        $acceptedAt = $acceptedAtMs ?? (int) floor(microtime(true) * 1000);

        return [
            'operationId' => $operationId,
            'itemId' => $itemId,
            'action' => $action,
            'deltas' => $deltas,
            'baseline' => self::normalizeCounters($baseline),
            'acceptedAt' => $acceptedAt,
            'expiresAt' => $acceptedAt + max(1000, $graceMs),
            'reconciled' => false,
        ];
    }

    /**
     * Keep merge helper free of Services/* so pure PHPUnit can load it.
     *
     * @param  array<string, mixed>  $stats
     * @return array{
     *     pending: int,
     *     needs_review: int,
     *     failed: int,
     *     review: int,
     *     approved: int,
     *     scheduled: int,
     *     published: int,
     *     running: int,
     * }
     */
    public static function normalizeCounters(array $stats): array
    {
        $needsReview = (int) ($stats['needs_review'] ?? $stats['recently_completed'] ?? $stats['ai_inbox'] ?? 0);

        return [
            'pending' => (int) ($stats['pending'] ?? 0),
            'needs_review' => $needsReview,
            'failed' => (int) ($stats['failed'] ?? 0),
            'review' => (int) ($stats['review'] ?? $stats['waiting_review'] ?? 0),
            'approved' => (int) ($stats['approved'] ?? 0),
            'scheduled' => (int) ($stats['scheduled'] ?? $stats['waiting_publish'] ?? 0),
            'published' => (int) ($stats['published'] ?? 0),
            'running' => (int) ($stats['running'] ?? 0),
        ];
    }
}
