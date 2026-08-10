<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs;

use Omnichannel\Addons\Agent\Extension\Registry\ExtensionRegistry;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentPack;
use Throwable;

/**
 * Discovers packs from builtin / extension SDK / database / imported.
 */
final class AgentPackDiscoveryService
{
    public function __construct(
        private readonly ?ExtensionRegistry $extensions = null,
        private readonly AgentPackEventEmitter $events = new AgentPackEventEmitter,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function discover(): array
    {
        $found = [];

        foreach ($this->builtinManifests() as $manifest) {
            $found[] = [
                'source' => 'builtin',
                'type' => 'builtin',
                'trust' => 'builtin',
                'manifest' => $manifest,
            ];
        }

        foreach ($this->extensionManifests() as $row) {
            $found[] = $row;
        }

        try {
            foreach (SeoAgentPack::query()->whereIn('type', ['custom', 'imported'])->get() as $pack) {
                $found[] = [
                    'source' => (string) $pack->source,
                    'type' => (string) $pack->type,
                    'trust' => (string) $pack->trust,
                    'pack_hash_id' => (string) $pack->hash_id,
                    'status' => (string) $pack->status,
                    'manifest' => [
                        'key' => $pack->key,
                        'name' => $pack->name,
                        'version' => $pack->version,
                        'type' => $pack->type,
                        'schema_version' => $pack->schema_version,
                    ],
                ];
            }
        } catch (Throwable) {
            // DB may be unavailable in pure unit context
        }

        foreach ($found as $row) {
            $key = (string) (($row['manifest']['key'] ?? '') ?: '');
            if ($key !== '') {
                $this->events->emit('pack.discovered', ['pack_key' => $key, 'type' => $row['type'] ?? null]);
            }
        }

        return $found;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function builtinManifests(): array
    {
        return [
            [
                'schema_version' => AgentPackConstants::SCHEMA_VERSION,
                'key' => 'omi.agent-core',
                'name' => 'OMI Agent Core',
                'version' => '1.0.0',
                'description' => 'Code-managed builtin pack marker (skills live in BuiltinSkillCatalog).',
                'provider' => 'omi',
                'sdk_constraint' => AgentPackConstants::SDK_MAJOR,
                'agent_workspace_constraint' => AgentPackConstants::WORKSPACE_VERSION,
                'type' => 'builtin',
                'skills' => [],
                'templates' => [],
                'translations' => [],
                'evaluation_datasets' => [],
                'permissions' => [],
                'dependencies' => [],
                'conflicts' => [],
                'metadata' => ['code_managed' => true],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extensionManifests(): array
    {
        if ($this->extensions === null) {
            return [];
        }
        $out = [];
        try {
            foreach ($this->extensions->installed() as $definition) {
                $manifest = $definition->manifest;
                // Extension may declare agent_pack in metadata via capabilities list — declarative only.
                $out[] = [
                    'source' => 'extension',
                    'type' => 'extension',
                    'trust' => 'trusted_extension',
                    'manifest' => [
                        'schema_version' => AgentPackConstants::SCHEMA_VERSION,
                        'key' => 'ext.'.str_replace('_', '-', (string) $manifest->id),
                        'name' => (string) $manifest->name,
                        'version' => (string) $manifest->version,
                        'description' => 'Extension SDK pack bridge',
                        'provider' => (string) $manifest->provider,
                        'sdk_constraint' => (int) $manifest->sdk,
                        'agent_workspace_constraint' => AgentPackConstants::WORKSPACE_VERSION,
                        'type' => 'extension',
                        'skills' => [],
                        'templates' => [],
                        'dependencies' => [],
                        'conflicts' => [],
                        'metadata' => ['extension_id' => $manifest->id],
                    ],
                ];
            }
        } catch (Throwable) {
            return [];
        }

        return $out;
    }
}
