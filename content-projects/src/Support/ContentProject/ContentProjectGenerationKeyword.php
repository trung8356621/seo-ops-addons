<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;

/**
 * Canonical generation keyword resolver for Content Project items.
 *
 * original keyword        = task.keyword (historical project input)
 * generation override     = task.generation_keyword_override (nullable)
 * effective keyword       = override ?: original (with legacy source_content fallback)
 */
final class ContentProjectGenerationKeyword
{
    public const REASON_DIRTY = 'generation_keyword_changed';

    public const SNAPSHOT_EFFECTIVE_KEY = 'effective_generation_keyword';

    public static function normalize(?string $value): string
    {
        return ContentProjectItemIdentity::normalize($value);
    }

    public static function originalKeyword(SeoProjectTask $task): string
    {
        $keyword = self::normalize(isset($task->keyword) ? (string) $task->keyword : null);
        if ($keyword !== '') {
            return $keyword;
        }

        $type = SeoProjectTask::normalizeType((string) ($task->type ?? SeoProjectTask::TYPE_CREATE));
        if ($type === SeoProjectTask::TYPE_IMPROVE) {
            return '';
        }

        return self::normalize(isset($task->source_content) ? (string) $task->source_content : null);
    }

    public static function overrideKeyword(SeoProjectTask $task): string
    {
        return self::normalize(isset($task->generation_keyword_override)
            ? (string) $task->generation_keyword_override
            : null);
    }

    public static function hasOverride(SeoProjectTask $task): bool
    {
        return self::overrideKeyword($task) !== '';
    }

    public static function effective(SeoProjectTask $task): string
    {
        $override = self::overrideKeyword($task);
        if ($override !== '') {
            return $override;
        }

        return self::originalKeyword($task);
    }

    /**
     * Resolve keyword used in the last successful generation from run-item input snapshot.
     *
     * @param  array<string, mixed>|null  $inputSnapshot
     */
    public static function lastGeneratedFromSnapshot(?array $inputSnapshot): ?string
    {
        if (! is_array($inputSnapshot) || $inputSnapshot === []) {
            return null;
        }

        $effective = self::normalize(
            isset($inputSnapshot[self::SNAPSHOT_EFFECTIVE_KEY])
                ? (string) $inputSnapshot[self::SNAPSHOT_EFFECTIVE_KEY]
                : null,
        );
        if ($effective !== '') {
            return $effective;
        }

        $runOverride = self::normalize(
            isset($inputSnapshot['generation_keyword_override'])
                ? (string) $inputSnapshot['generation_keyword_override']
                : null,
        );
        if ($runOverride !== '') {
            return $runOverride;
        }

        $keyword = self::normalize(isset($inputSnapshot['keyword']) ? (string) $inputSnapshot['keyword'] : null);

        return $keyword !== '' ? $keyword : null;
    }

    public static function isDirty(SeoProjectTask $task, ?string $lastGeneratedKeyword, bool $hasPriorGeneration): bool
    {
        if (! $hasPriorGeneration) {
            return false;
        }

        $effective = self::effective($task);
        if ($effective === '') {
            return false;
        }

        $last = self::normalize($lastGeneratedKeyword ?? '');
        if ($last === '') {
            return self::hasOverride($task);
        }

        return $last !== $effective;
    }

    /**
     * Clear override when user enters the same value as original keyword.
     */
    public static function normalizeOverrideInput(SeoProjectTask $task, ?string $input): ?string
    {
        $normalized = self::normalize($input);
        if ($normalized === '') {
            return null;
        }

        $original = self::originalKeyword($task);
        if ($original !== '' && $normalized === $original) {
            return null;
        }

        return $normalized;
    }
}
