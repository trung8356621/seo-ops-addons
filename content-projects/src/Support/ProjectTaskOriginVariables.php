<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support;

/**
 * Stamp / read SeoProjectTask id trên TaskTestContext.variables (không đổi schema rộng).
 */
final class ProjectTaskOriginVariables
{
    public const KEY = '_seo_project_task_id';

    /**
     * @param  array<string, string>  $variables
     * @return array<string, string>
     */
    public static function stamp(array $variables, int $projectTaskId): array
    {
        if ($projectTaskId <= 0) {
            return $variables;
        }

        $variables[self::KEY] = (string) $projectTaskId;

        return $variables;
    }

    /**
     * @param  array<string, string>  $variables
     */
    public static function read(array $variables): ?int
    {
        $raw = $variables[self::KEY] ?? null;
        if (! is_string($raw) && ! is_numeric($raw)) {
            return null;
        }

        $id = (int) $raw;

        return $id > 0 ? $id : null;
    }
}
