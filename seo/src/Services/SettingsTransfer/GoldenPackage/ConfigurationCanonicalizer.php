<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\SettingsTransfer\GoldenPackage;

/**
 * Canonicalizes full-configuration payloads for semantic equality comparison.
 *
 * Ignored (non-semantic): exported_at, local numeric DB ids, JSON key order,
 * UUID remapping artifacts that are already portable, transient runtime metadata.
 *
 * Preserved (semantic): prompt body/settings, workflow graphs, hook bindings,
 * settings field values, provider template definitions (credential-free).
 */
final class ConfigurationCanonicalizer
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function canonicalize(array $payload): array
    {
        $out = $payload;

        if (isset($out['meta']) && is_array($out['meta'])) {
            unset($out['meta']['exported_at']);
        }

        if (isset($out['settings']) && is_array($out['settings'])) {
            $out['settings'] = $this->canonicalizeSettings($out['settings']);
        }

        if (isset($out['prompts'])) {
            $out['prompts'] = $this->canonicalizePrompts($out['prompts']);
        }

        if (isset($out['tasks']) && is_array($out['tasks'])) {
            $out['tasks'] = $this->canonicalizeTasks($out['tasks']);
        }

        if (isset($out['provider_templates']) && is_array($out['provider_templates'])) {
            $out['provider_templates'] = $this->canonicalizeProviderTemplates($out['provider_templates']);
        }

        unset($out['_excluded']);

        return $this->sortKeys($out);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function fingerprint(array $payload): string
    {
        $canonical = $this->canonicalize($payload);
        $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            throw new \RuntimeException('Unable to encode canonical configuration JSON.');
        }

        return hash('sha256', $json);
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    public function equals(array $left, array $right): bool
    {
        return hash_equals($this->fingerprint($left), $this->fingerprint($right));
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function canonicalizeSettings(array $settings): array
    {
        if (isset($settings['workflows']) && is_array($settings['workflows'])) {
            $settings['workflows'] = $this->canonicalizeWorkflowSettings($settings['workflows']);
        }

        if (isset($settings['ai']) && is_array($settings['ai'])) {
            $settings['ai'] = $this->canonicalizeAiSettings($settings['ai']);
        }

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $workflows
     * @return array<string, mixed>
     */
    private function canonicalizeWorkflowSettings(array $workflows): array
    {
        foreach (['prompt_bindings', 'task_bindings'] as $key) {
            if (! isset($workflows[$key]) || ! is_array($workflows[$key])) {
                continue;
            }
            $normalized = [];
            foreach ($workflows[$key] as $slot => $ref) {
                if (! is_array($ref)) {
                    $normalized[(string) $slot] = $ref;

                    continue;
                }
                $normalized[(string) $slot] = [
                    'portable_uuid' => (string) ($ref['portable_uuid'] ?? ''),
                    'name' => (string) ($ref['name'] ?? ''),
                ];
            }
            ksort($normalized);
            $workflows[$key] = $normalized;
        }

        return $workflows;
    }

    /**
     * @param  array<string, mixed>  $ai
     * @return array<string, mixed>
     */
    private function canonicalizeAiSettings(array $ai): array
    {
        if (isset($ai['connections']) && is_array($ai['connections'])) {
            $connections = [];
            foreach ($ai['connections'] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                unset($row['id'], $row['connection_id'], $row['api_connection_id']);
                if (isset($row['metadata']) && is_array($row['metadata'])) {
                    unset(
                        $row['metadata']['model_catalog'],
                        $row['metadata']['last_error'],
                        $row['metadata']['catalog_hash'],
                        $row['metadata']['last_sync_at'],
                        $row['metadata']['last_success_at'],
                        $row['metadata']['sync_started_at'],
                        $row['metadata']['openrouter_free_pool_health'],
                        $row['metadata']['model_strikes'],
                        $row['metadata']['cooldown_until'],
                        $row['metadata']['quarantine_until'],
                        $row['metadata']['recent_failures'],
                        $row['metadata']['seo_ai_model_id'],
                    );
                }
                $connections[] = $row;
            }
            usort($connections, static function (array $a, array $b): int {
                $ka = (string) ($a['connection_ref']['connection_key'] ?? $a['name'] ?? '');
                $kb = (string) ($b['connection_ref']['connection_key'] ?? $b['name'] ?? '');

                return $ka <=> $kb;
            });
            $ai['connections'] = $connections;
        }

        return $ai;
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $prompts
     * @return array{schema_version?: string, prompts: list<array<string, mixed>>}
     */
    private function canonicalizePrompts(array $prompts): array
    {
        $list = [];
        $schema = null;
        if (isset($prompts['prompts']) && is_array($prompts['prompts'])) {
            $schema = isset($prompts['schema_version']) ? (string) $prompts['schema_version'] : null;
            $list = $prompts['prompts'];
        } elseif (array_is_list($prompts)) {
            $list = $prompts;
        }

        $normalized = [];
        foreach ($list as $row) {
            if (! is_array($row)) {
                continue;
            }
            unset($row['id'], $row['user_id'], $row['created_at'], $row['updated_at'], $row['deleted_at']);
            $normalized[] = $row;
        }

        usort($normalized, static function (array $a, array $b): int {
            $ua = (string) ($a['portable_uuid'] ?? '');
            $ub = (string) ($b['portable_uuid'] ?? '');
            if ($ua !== '' || $ub !== '') {
                return $ua <=> $ub;
            }

            return ((string) ($a['name'] ?? '')) <=> ((string) ($b['name'] ?? ''));
        });

        $out = ['prompts' => $normalized];
        if ($schema !== null) {
            $out['schema_version'] = $schema;
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $tasks
     * @return list<array<string, mixed>>
     */
    private function canonicalizeTasks(array $tasks): array
    {
        $normalized = [];
        foreach ($tasks as $row) {
            if (! is_array($row)) {
                continue;
            }
            unset($row['id'], $row['user_id'], $row['created_at'], $row['updated_at']);
            if (isset($row['flow_data']) && is_array($row['flow_data'])) {
                $row['flow_data'] = $this->canonicalizeFlowData($row['flow_data']);
            }
            $normalized[] = $row;
        }

        usort($normalized, static function (array $a, array $b): int {
            $ua = (string) ($a['portable_uuid'] ?? '');
            $ub = (string) ($b['portable_uuid'] ?? '');
            if ($ua !== '' || $ub !== '') {
                return $ua <=> $ub;
            }

            return ((string) ($a['name'] ?? '')) <=> ((string) ($b['name'] ?? ''));
        });

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $flow
     * @return array<string, mixed>
     */
    private function canonicalizeFlowData(array $flow): array
    {
        unset($flow['task_test_results'], $flow['test_results'], $flow['runtime_state']);

        if (isset($flow['nodes']) && is_array($flow['nodes'])) {
            $nodes = [];
            foreach ($flow['nodes'] as $node) {
                if (! is_array($node)) {
                    continue;
                }
                if (isset($node['data']) && is_array($node['data'])) {
                    $data = $node['data'];
                    unset($data['promptId'], $data['prompt_id']);
                    if (isset($data['prompt_ref']) && is_array($data['prompt_ref'])) {
                        $data['prompt_ref'] = [
                            'portable_uuid' => (string) ($data['prompt_ref']['portable_uuid'] ?? ''),
                            'name' => (string) ($data['prompt_ref']['name'] ?? ''),
                        ];
                    }
                    $node['data'] = $data;
                }
                $nodes[] = $node;
            }
            usort($nodes, static fn (array $a, array $b): int => ((string) ($a['id'] ?? '')) <=> ((string) ($b['id'] ?? '')));
            $flow['nodes'] = $nodes;
        }

        if (isset($flow['edges']) && is_array($flow['edges'])) {
            $edges = array_values(array_filter($flow['edges'], static fn ($e): bool => is_array($e)));
            usort($edges, static fn (array $a, array $b): int => ((string) ($a['id'] ?? '')) <=> ((string) ($b['id'] ?? '')));
            $flow['edges'] = $edges;
        }

        return $flow;
    }

    /**
     * @param  list<mixed>  $templates
     * @return list<array<string, mixed>>
     */
    private function canonicalizeProviderTemplates(array $templates): array
    {
        $normalized = [];
        foreach ($templates as $row) {
            if (! is_array($row)) {
                continue;
            }
            unset($row['id'], $row['user_id'], $row['updated_by'], $row['created_at'], $row['updated_at']);
            if (isset($row['credential']) && is_array($row['credential'])) {
                $row['credential'] = [
                    'configured' => false,
                    'exported' => false,
                ];
            }
            $normalized[] = $row;
        }

        usort($normalized, static function (array $a, array $b): int {
            $ka = (string) ($a['provider']['key'] ?? $a['provider_key'] ?? '');
            $kb = (string) ($b['provider']['key'] ?? $b['provider_key'] ?? '');

            return $ka <=> $kb;
        });

        return $normalized;
    }

    private function sortKeys(mixed $node): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        if (array_is_list($node)) {
            return array_map(fn (mixed $item): mixed => $this->sortKeys($item), $node);
        }

        ksort($node);
        foreach ($node as $key => $value) {
            $node[$key] = $this->sortKeys($value);
        }

        return $node;
    }
}
