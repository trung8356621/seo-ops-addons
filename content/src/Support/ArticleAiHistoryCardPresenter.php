<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

/**
 * Presentation helpers for Article AI History list cards.
 * Does not alter persistence or execution semantics.
 */
final class ArticleAiHistoryCardPresenter
{
    /**
     * Compact model display: drop provider prefix; free/paid tag from metadata when provided.
     *
     * Example: `nvidia/nemotron-3-ultra-550b-a55b:free` → `nemotron-3-ultra-550b-a55b · free`
     * With isFreeCandidate=true and id without `:free` → append ` · free`.
     */
    public static function compactModel(string $modelId, ?bool $isFreeCandidate = null): string
    {
        $full = trim($modelId);
        if ($full === '') {
            return '';
        }

        if (preg_match('/^\d+\s+models?\s+used$/i', $full) === 1) {
            return $full;
        }

        if (strcasecmp($full, 'Unknown model') === 0) {
            return 'Unknown model';
        }

        $withoutProvider = $full;
        $slashPos = strpos($full, '/');
        if ($slashPos !== false) {
            $withoutProvider = substr($full, $slashPos + 1);
        }

        $colonPos = strrpos($withoutProvider, ':');
        $base = $withoutProvider;
        $suffixFromId = null;
        if ($colonPos !== false) {
            $base = substr($withoutProvider, 0, $colonPos);
            $suffixFromId = substr($withoutProvider, $colonPos + 1);
            if ($base === '' || $suffixFromId === '') {
                $base = $withoutProvider;
                $suffixFromId = null;
            }
        }

        if ($isFreeCandidate === true) {
            return $base.' · free';
        }

        if ($isFreeCandidate === false) {
            return $base;
        }

        // Legacy rows without is_free_candidate: keep colon-suffix display.
        if ($suffixFromId !== null) {
            return $base.' · '.$suffixFromId;
        }

        return $base;
    }

    /**
     * Compact attempt + time line, optional Retry tag prefix.
     *
     * @return array{is_retry: bool, attempt_label: ?string, time_label: ?string}
     */
    public static function attemptMeta(
        mixed $attempt,
        mixed $ranAt,
        ?string $executionType = null,
    ): array {
        $attemptNumber = is_numeric($attempt) ? (int) $attempt : null;
        $type = strtolower(trim((string) $executionType));
        $isRetry = $type === 'retry' || ($attemptNumber !== null && $attemptNumber > 1);

        $timeLabel = null;
        if ($ranAt instanceof \DateTimeInterface) {
            $timeLabel = $ranAt->format('H:i');
        } elseif (is_string($ranAt) && trim($ranAt) !== '') {
            try {
                $timeLabel = (new \DateTimeImmutable(trim($ranAt)))->format('H:i');
            } catch (\Throwable) {
                $timeLabel = null;
            }
        }

        $attemptLabel = $attemptNumber !== null && $attemptNumber > 0
            ? 'Attempt #'.$attemptNumber
            : null;

        return [
            'is_retry' => $isRetry,
            'attempt_label' => $attemptLabel,
            'time_label' => $timeLabel,
        ];
    }

    public static function groupDateLabel(mixed $ranAt): ?string
    {
        if ($ranAt instanceof \DateTimeInterface) {
            return $ranAt->format('d/m/Y');
        }

        if (is_string($ranAt) && trim($ranAt) !== '') {
            try {
                return (new \DateTimeImmutable(trim($ranAt)))->format('d/m/Y');
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    public static function wordCountLabel(mixed $wordCount): ?string
    {
        if (! is_numeric($wordCount)) {
            return null;
        }

        $n = (int) $wordCount;
        if ($n <= 0) {
            return null;
        }

        return $n.' từ';
    }
}
