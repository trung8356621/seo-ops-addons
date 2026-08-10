<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

/**
 * Derive failure_type for Failed quick filter (no migrate required).
 * Prefer explicit row.failure_type; else map legacy message/source.
 */
final class ContentProjectFailureTypeMapper
{
    public const ALL = '';

    public const PROMPT = 'prompt';

    public const MODEL = 'model';

    public const QUEUE = 'queue';

    public const TIMEOUT = 'timeout';

    public const VALIDATION = 'validation';

    public const WORDPRESS = 'wordpress';

    public const OTHER = 'other';

    /**
     * @return list<string>
     */
    public static function filterKeys(): array
    {
        return [
            self::PROMPT,
            self::MODEL,
            self::QUEUE,
            self::TIMEOUT,
            self::VALIDATION,
            self::WORDPRESS,
            self::OTHER,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function resolve(array $row): string
    {
        $explicit = strtolower(trim((string) ($row['failure_type'] ?? '')));
        if ($explicit !== '' && in_array($explicit, self::filterKeys(), true)) {
            return $explicit;
        }

        $source = strtolower(trim((string) ($row['current_error_source'] ?? '')));
        $message = strtolower(trim((string) ($row['message'] ?? '')));
        $hay = $source.' '.$message.' '.strtolower(trim((string) ($row['run_item_error'] ?? '')));

        if (str_contains($hay, 'timeout') || str_contains($hay, 'timed out') || str_contains($hay, 'time out')) {
            return self::TIMEOUT;
        }
        if (str_contains($hay, 'wordpress') || str_contains($hay, 'wp ') || str_contains($hay, 'wp_')
            || $source === 'publish') {
            return self::WORDPRESS;
        }
        if (str_contains($hay, 'validat') || str_contains($hay, 'schema') || str_contains($hay, 'invalid')) {
            return self::VALIDATION;
        }
        if (str_contains($hay, 'prompt') || str_contains($hay, 'hook')) {
            return self::PROMPT;
        }
        if (str_contains($hay, 'model') || str_contains($hay, 'openai') || str_contains($hay, 'anthropic')
            || str_contains($hay, 'rate limit') || str_contains($hay, 'quota')) {
            return self::MODEL;
        }
        if (str_contains($hay, 'queue') || str_contains($hay, 'worker') || str_contains($hay, 'job')) {
            return self::QUEUE;
        }

        return self::OTHER;
    }
}
