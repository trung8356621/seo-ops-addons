<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Domain;

/**
 * Map prior widget outputs into next widget inputs (V1 sequential workflow).
 * SEO-OPS prepares payload; Runner executes.
 */
final class WorkflowOutputMapper
{
    /**
     * @param  list<array{widget_key: string, input?: array<string, mixed>, output_bindings?: array<string, string>}>  $steps
     * @param  array<string, mixed>  $taskInputs
     * @param  array<string, array<string, mixed>>  $priorOutputs  keyed by widget_key
     * @return array{ok: bool, steps: list<array<string, mixed>>, errors: list<string>}
     */
    public function buildRunnerPayload(array $steps, array $taskInputs, array $priorOutputs = []): array
    {
        $errors = [];
        $built = [];

        foreach ($steps as $index => $step) {
            $widgetKey = trim((string) ($step['widget_key'] ?? ''));
            if ($widgetKey === '') {
                $errors[] = 'step_missing_widget:'.$index;
                continue;
            }

            $input = is_array($step['input'] ?? null) ? $step['input'] : [];
            $input = array_merge($taskInputs, $input);

            $bindings = is_array($step['output_bindings'] ?? null) ? $step['output_bindings'] : [];
            foreach ($bindings as $targetField => $sourcePath) {
                $value = $this->resolvePath($priorOutputs, (string) $sourcePath);
                if ($value === null) {
                    $errors[] = 'binding_unresolved:'.$widgetKey.'.'.$targetField;
                    continue;
                }
                $input[(string) $targetField] = $value;
            }

            $built[] = [
                'index' => $index,
                'widget_key' => $widgetKey,
                'input' => $input,
            ];
        }

        return [
            'ok' => $errors === [],
            'steps' => $built,
            'errors' => $errors,
        ];
    }

    /**
     * Format: {widget_key}.{field} — widget_key may itself contain dots
     * (e.g. facebook.group.post.post_url → key facebook.group.post, field post_url).
     *
     * @param  array<string, array<string, mixed>>  $priorOutputs
     */
    private function resolvePath(array $priorOutputs, string $path): mixed
    {
        $path = trim($path);
        if ($path === '' || ! str_contains($path, '.')) {
            return null;
        }

        // Longest matching widget_key prefix wins.
        $keys = array_keys($priorOutputs);
        usort($keys, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($keys as $widgetKey) {
            $prefix = $widgetKey.'.';
            if (! str_starts_with($path, $prefix)) {
                continue;
            }
            $field = substr($path, strlen($prefix));
            if ($field === '') {
                return null;
            }
            $bucket = $priorOutputs[$widgetKey] ?? null;
            if (! is_array($bucket) || ! array_key_exists($field, $bucket)) {
                return null;
            }

            return $bucket[$field];
        }

        // Fallback: first segment / remainder (legacy short keys).
        $parts = explode('.', $path, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$widgetKey, $field] = $parts;
        $bucket = $priorOutputs[$widgetKey] ?? null;
        if (! is_array($bucket) || ! array_key_exists($field, $bucket)) {
            return null;
        }

        return $bucket[$field];
    }
}
