<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Support;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use Omnichannel\Addons\Agent\Automation\Exceptions\AutomationException;

/**
 * Chỉ hỗ trợ placeholder whitelist: event.* payload.* context.* subject.* previous.*
 */
final class AutomationInputMapper
{
    private const ROOTS = ['event', 'payload', 'context', 'subject', 'previous'];

    /**
     * @param  array<string, mixed>  $mapping
     * @param  array{
     *   event: array<string, mixed>,
     *   payload: array<string, mixed>,
     *   context: array<string, mixed>,
     *   subject: array<string, mixed>,
     *   previous: array<string, mixed>
     * }  $sources
     * @return array<string, mixed>
     */
    public function map(array $mapping, array $sources): array
    {
        $out = [];
        foreach ($mapping as $key => $value) {
            $out[$key] = $this->resolveValue($value, $sources);
        }

        return $out;
    }

    public function resolveValue(mixed $value, array $sources): mixed
    {
        if (is_array($value)) {
            $mapped = [];
            foreach ($value as $k => $v) {
                $mapped[$k] = $this->resolveValue($v, $sources);
            }

            return $mapped;
        }

        if (! is_string($value)) {
            return $value;
        }

        if (preg_match('/^\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}$/', $value, $m) === 1) {
            return $this->resolvePath($m[1], $sources);
        }

        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            function (array $m) use ($sources): string {
                $resolved = $this->resolvePath($m[1], $sources);
                if (is_scalar($resolved) || $resolved === null) {
                    return (string) ($resolved ?? '');
                }

                return '';
            },
            $value,
        );
    }

    /**
     * @param  array<string, mixed>  $sources
     */
    public function resolvePath(string $path, array $sources): mixed
    {
        $parts = explode('.', $path);
        $root = $parts[0] ?? '';
        if (! in_array($root, self::ROOTS, true)) {
            throw new AutomationException(
                BusinessHookErrorCode::InvalidInputMapping->value,
                "Input mapping path [{$path}] is not allowed.",
            );
        }

        if (preg_match('/[^a-zA-Z0-9_.]/', $path) === 1) {
            throw new AutomationException(
                BusinessHookErrorCode::InvalidInputMapping->value,
                "Input mapping path [{$path}] contains illegal characters.",
            );
        }

        $cursor = $sources[$root] ?? [];
        for ($i = 1, $n = count($parts); $i < $n; $i++) {
            $segment = $parts[$i];
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }
}
