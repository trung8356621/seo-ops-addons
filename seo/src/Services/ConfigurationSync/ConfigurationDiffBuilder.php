<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\ConfigurationSync;

/**
 * Builds the sanitized diff shape accepted by POST /config/sync.
 * Connection rows are never copied into the outbound diff.
 */
final class ConfigurationDiffBuilder
{
    /**
     * @param  array<string, mixed>  $golden
     * @param  array<string, mixed>  $live
     * @return array{settings: array<string, mixed>, prompts: list<array<string, mixed>>, workflows: list<array<string, mixed>>}
     */
    public function build(array $golden, array $live): array
    {
        return [
            'settings' => $this->settingsDiff($golden, $live),
            'prompts' => $this->componentDiff($this->promptRows($golden), $this->promptRows($live), 'content'),
            'workflows' => $this->workflowDiff($this->taskRows($golden), $this->taskRows($live)),
        ];
    }

    /**
     * @param  array<string, mixed>  $diff
     */
    public function isEmpty(array $diff): bool
    {
        $settings = $diff['settings'] ?? [];
        $prompts = $diff['prompts'] ?? [];
        $workflows = $diff['workflows'] ?? [];

        return $settings === [] && $prompts === [] && $workflows === [];
    }

    /**
     * @param  array<string, mixed>  $golden
     * @param  array<string, mixed>  $live
     * @return array<string, mixed>
     */
    private function settingsDiff(array $golden, array $live): array
    {
        $left = $this->settingsWithoutConnections(is_array($golden['settings'] ?? null) ? $golden['settings'] : []);
        $right = $this->settingsWithoutConnections(is_array($live['settings'] ?? null) ? $live['settings'] : []);

        return $this->changedTree($left, $right);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function settingsWithoutConnections(array $settings): array
    {
        if (isset($settings['ai']) && is_array($settings['ai'])) {
            unset($settings['ai']['connections']);
            if ($settings['ai'] === []) {
                unset($settings['ai']);
            }
        }

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @return array<string, mixed>
     */
    private function changedTree(array $left, array $right): array
    {
        $out = [];
        $keys = array_unique(array_merge(array_keys($left), array_keys($right)));
        sort($keys);
        foreach ($keys as $key) {
            $a = $left[$key] ?? null;
            $b = $right[$key] ?? null;
            if ($a === $b) {
                continue;
            }
            if (is_array($a) && is_array($b) && ! array_is_list($a) && ! array_is_list($b)) {
                $child = $this->changedTree($a, $b);
                if ($child !== []) {
                    $out[$key] = $child;
                }
                continue;
            }
            $out[$key] = $b;
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, mixed>>  $golden
     * @param  array<string, array<string, mixed>>  $live
     * @return list<array<string, mixed>>
     */
    private function componentDiff(array $golden, array $live, string $contentKey): array
    {
        $rows = [];
        foreach ($golden as $uuid => $before) {
            if (! isset($live[$uuid])) {
                $rows[] = [
                    'portable_uuid' => $uuid,
                    'name' => (string) ($before['name'] ?? ''),
                    'change_type' => 'deleted',
                ];
                continue;
            }
            $after = $live[$uuid];
            if ($this->semanticRow($before, $contentKey) === $this->semanticRow($after, $contentKey)) {
                continue;
            }
            $old = (string) ($before[$contentKey] ?? $before['markdown_content'] ?? '');
            $new = (string) ($after[$contentKey] ?? $after['markdown_content'] ?? '');
            $rows[] = [
                'portable_uuid' => $uuid,
                'name' => (string) ($after['name'] ?? ''),
                'hook_key' => (string) ($after['hook'] ?? $after['hook_key'] ?? ''),
                'change_type' => 'modified',
                'content_fingerprint' => hash('sha256', $new),
                'changes' => [
                    'content' => ['old' => $old, 'new' => $new],
                ],
            ];
        }
        foreach ($live as $uuid => $after) {
            if (isset($golden[$uuid])) {
                continue;
            }
            $content = (string) ($after[$contentKey] ?? '');
            $rows[] = [
                'portable_uuid' => $uuid,
                'name' => (string) ($after['name'] ?? ''),
                'hook_key' => (string) ($after['hook'] ?? $after['hook_key'] ?? ''),
                'change_type' => 'added',
                'content_fingerprint' => hash('sha256', $content),
                'changes' => [
                    'content' => ['old' => '', 'new' => $content],
                ],
            ];
        }
        usort($rows, static fn (array $a, array $b): int => ((string) $a['portable_uuid']) <=> ((string) $b['portable_uuid']));

        return $rows;
    }

    /**
     * @param  array<string, array<string, mixed>>  $golden
     * @param  array<string, array<string, mixed>>  $live
     * @return list<array<string, mixed>>
     */
    private function workflowDiff(array $golden, array $live): array
    {
        $rows = [];
        foreach ($golden as $uuid => $before) {
            if (! isset($live[$uuid])) {
                $rows[] = [
                    'portable_uuid' => $uuid,
                    'name' => (string) ($before['name'] ?? ''),
                    'change_type' => 'deleted',
                ];
                continue;
            }
            $after = $live[$uuid];
            $beforeFlow = is_array($before['flow_data'] ?? null) ? $before['flow_data'] : [];
            $afterFlow = is_array($after['flow_data'] ?? null) ? $after['flow_data'] : [];
            $beforeJson = json_encode($beforeFlow);
            $afterJson = json_encode($afterFlow);
            if ($beforeJson === $afterJson && (string) ($before['name'] ?? '') === (string) ($after['name'] ?? '')) {
                continue;
            }
            $rows[] = [
                'portable_uuid' => $uuid,
                'name' => (string) ($after['name'] ?? ''),
                'change_type' => 'modified',
                'workflow_fingerprint' => hash('sha256', (string) $afterJson),
                'referenced_prompt_portable_uuids' => $this->referencedPrompts($afterFlow),
                'changes' => [
                    'node_count' => [
                        'old' => count($beforeFlow['nodes'] ?? []),
                        'new' => count($afterFlow['nodes'] ?? []),
                    ],
                    'edge_count' => [
                        'old' => count($beforeFlow['edges'] ?? []),
                        'new' => count($afterFlow['edges'] ?? []),
                    ],
                ],
            ];
        }
        foreach ($live as $uuid => $after) {
            if (isset($golden[$uuid])) {
                continue;
            }
            $flow = is_array($after['flow_data'] ?? null) ? $after['flow_data'] : [];
            $rows[] = [
                'portable_uuid' => $uuid,
                'name' => (string) ($after['name'] ?? ''),
                'change_type' => 'added',
                'workflow_fingerprint' => hash('sha256', (string) json_encode($flow)),
                'referenced_prompt_portable_uuids' => $this->referencedPrompts($flow),
                'changes' => [
                    'node_count' => ['old' => 0, 'new' => count($flow['nodes'] ?? [])],
                    'edge_count' => ['old' => 0, 'new' => count($flow['edges'] ?? [])],
                ],
            ];
        }
        usort($rows, static fn (array $a, array $b): int => ((string) $a['portable_uuid']) <=> ((string) $b['portable_uuid']));

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function semanticRow(array $row, string $contentKey): array
    {
        return [
            'name' => (string) ($row['name'] ?? ''),
            'content' => (string) ($row[$contentKey] ?? $row['markdown_content'] ?? ''),
            'hook' => (string) ($row['hook'] ?? $row['hook_key'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, array<string, mixed>>
     */
    private function promptRows(array $payload): array
    {
        $prompts = $payload['prompts'] ?? [];
        $list = [];
        if (is_array($prompts) && isset($prompts['prompts']) && is_array($prompts['prompts'])) {
            $list = $prompts['prompts'];
        } elseif (is_array($prompts) && array_is_list($prompts)) {
            $list = $prompts;
        }

        return $this->indexByUuid($list);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, array<string, mixed>>
     */
    private function taskRows(array $payload): array
    {
        $tasks = $payload['tasks'] ?? $payload['workflows'] ?? [];

        return $this->indexByUuid(is_array($tasks) ? $tasks : []);
    }

    /**
     * @param  array<mixed>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexByUuid(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $uuid = trim((string) ($row['portable_uuid'] ?? ''));
            if ($uuid === '') {
                continue;
            }
            $map[$uuid] = $row;
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $flow
     * @return list<string>
     */
    private function referencedPrompts(array $flow): array
    {
        $ids = [];
        foreach ($flow['nodes'] ?? [] as $node) {
            if (! is_array($node)) {
                continue;
            }
            $data = is_array($node['data'] ?? null) ? $node['data'] : [];
            $ref = is_array($data['prompt_ref'] ?? null) ? $data['prompt_ref'] : [];
            $uuid = trim((string) ($ref['portable_uuid'] ?? ''));
            if ($uuid !== '') {
                $ids[] = $uuid;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
