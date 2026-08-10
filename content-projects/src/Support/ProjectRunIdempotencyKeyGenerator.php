<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support;

/**
 * Idempotency key cho seo_project_run_items.
 * operationVersion do caller cung cấp — không mặc định dùng updated_at.
 */
final class ProjectRunIdempotencyKeyGenerator
{
    public function generate(
        int|string $taskId,
        string $action,
        string $operationVersion,
    ): string {
        $payload = json_encode([
            'task_id' => (string) $taskId,
            'action' => trim($action),
            'operation_version' => trim($operationVersion),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return hash('sha256', $payload);
    }

    /**
     * @param  array<string, mixed>  $relevantInput
     */
    public function contentVersion(array $relevantInput): string
    {
        ksort($relevantInput);

        $normalized = [];
        foreach ($relevantInput as $key => $value) {
            $normalized[(string) $key] = $this->normalizeValue($value);
        }

        return hash(
            'sha256',
            json_encode(
                $normalized,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        );
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            if ($this->isList($value)) {
                return array_map(fn (mixed $item): mixed => $this->normalizeValue($item), $value);
            }

            ksort($value);
            $out = [];
            foreach ($value as $key => $item) {
                $out[(string) $key] = $this->normalizeValue($item);
            }

            return $out;
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        return (string) $value;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}
