<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities;

use Omnichannel\Addons\Agent\Extension\ExtensionStateStore;
use Omnichannel\Addons\Agent\Extension\Registry\ExtensionCapabilityRegistry;
use App\Support\RuntimeLogger;

/**
 * Canonical capability surface for the Agent Gateway/MCP — merges core
 * `content_project.*` capabilities with capabilities contributed by enabled
 * extensions.
 *
 * Rules:
 * - Core capability names (`content_project.` prefix, or any name already
 *   owned by {@see ContentProjectCapabilityRegistry}) can never be
 *   overridden by an extension.
 * - Any name collision (extension vs extension, or extension vs core) is
 *   excluded entirely from the exposed set and recorded via conflicts().
 * - Disabled extensions never contribute capabilities to all()/get().
 */
final class CanonicalCapabilityRegistry
{
    private const CORE_PREFIX = 'content_project.';

    /** @var list<array<string, mixed>>|null */
    private ?array $merged = null;

    /** @var list<array{name: string, sources: list<string>}>|null */
    private ?array $conflicts = null;

    public function __construct(
        private readonly ContentProjectCapabilityRegistry $core,
        private readonly ExtensionCapabilityRegistry $extensionCapabilities,
        private readonly ExtensionStateStore $extensionState,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->build()['merged'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $name): ?array
    {
        foreach ($this->all() as $cap) {
            if (($cap['name'] ?? '') === $name) {
                return $cap;
            }
        }

        return null;
    }

    /**
     * @return list<array{name: string, sources: list<string>}>
     */
    public function conflicts(): array
    {
        return $this->build()['conflicts'];
    }

    public function isAgentWriteExposed(string $name): bool
    {
        if ($this->core->get($name) !== null) {
            return $this->core->isAgentWriteExposed($name);
        }

        // get() already excludes conflicted names and caps from disabled
        // extensions, so a non-null hit here is safe to expose as write.
        $cap = $this->get($name);
        if ($cap === null) {
            return false;
        }

        if ((string) ($cap['risk_level'] ?? '') !== 'write') {
            return false;
        }

        if ((bool) ($cap['internal'] ?? false)) {
            return false;
        }

        return (bool) ($cap['agent_exposed'] ?? true);
    }

    /**
     * MCP write surface — core delegates to {@see ContentProjectCapabilityRegistry::isMcpWriteExposed()};
     * extension caps fall back to the agent-exposed check plus an explicit
     * `mcp_exposed === false` opt-out.
     */
    public function isMcpWriteExposed(string $name): bool
    {
        if ($this->core->get($name) !== null) {
            return $this->core->isMcpWriteExposed($name);
        }

        if (! $this->isAgentWriteExposed($name)) {
            return false;
        }

        $cap = $this->get($name);

        return ($cap['mcp_exposed'] ?? null) !== false;
    }

    /**
     * @return array{merged: list<array<string, mixed>>, conflicts: list<array{name: string, sources: list<string>}>}
     */
    private function build(): array
    {
        if ($this->merged !== null && $this->conflicts !== null) {
            return ['merged' => $this->merged, 'conflicts' => $this->conflicts];
        }

        $coreCaps = $this->core->all();

        /** @var array<string, bool> $coreNames */
        $coreNames = [];
        foreach ($coreCaps as $cap) {
            $name = (string) ($cap['name'] ?? '');
            if ($name !== '') {
                $coreNames[$name] = true;
            }
        }

        /** @var array<string, list<string>> $sourcesByName */
        $sourcesByName = [];
        /** @var array<string, array<string, mixed>> $extensionCapsByName */
        $extensionCapsByName = [];

        foreach ($this->extensionCapabilities->all() as $extensionId => $contributor) {
            foreach ($contributor->capabilities() as $rawCap) {
                if (! is_array($rawCap)) {
                    continue;
                }

                $name = trim((string) ($rawCap['name'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $sourcesByName[$name][] = $extensionId;

                $enriched = $rawCap;
                $enriched['extension_id'] = $extensionId;
                $extensionCapsByName[$name] = $enriched;
            }
        }

        $conflicts = [];
        $merged = $coreCaps;

        foreach ($sourcesByName as $name => $sources) {
            $sources = array_values(array_unique($sources));
            $isCoreOwned = isset($coreNames[$name]) || str_starts_with($name, self::CORE_PREFIX);

            if ($isCoreOwned) {
                $conflictSources = array_values(array_unique(array_merge(['core'], $sources)));
                $conflicts[] = ['name' => $name, 'sources' => $conflictSources];
                $this->logConflict($name, $conflictSources);

                continue;
            }

            if (count($sources) > 1) {
                $conflicts[] = ['name' => $name, 'sources' => $sources];
                $this->logConflict($name, $sources);

                continue;
            }

            $extensionId = $sources[0];
            if (! $this->extensionState->isEnabled($extensionId)) {
                continue;
            }

            $cap = $extensionCapsByName[$name] ?? null;
            if ($cap !== null) {
                $merged[] = $cap;
            }
        }

        $this->merged = $merged;
        $this->conflicts = $conflicts;

        return ['merged' => $merged, 'conflicts' => $conflicts];
    }

    /**
     * @param  list<string>  $sources
     */
    private function logConflict(string $name, array $sources): void
    {
        RuntimeLogger::warning('extension.capability_conflict', [
            'capability' => $name,
            'sources' => $sources,
        ]);
    }
}
