<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Routing;

use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentApp;
use Omnichannel\Addons\AgentRuntime\Integration\AgentIntegrationRegistry;
use Omnichannel\Addons\AgentRuntime\Integration\SeoOpsAgentIntegration;
use Throwable;

final class SemanticRoutingConfig
{
    public const APP_KEY = 'seo-ops';

    public const META_KEY = 'semantic_routing';

    /** @param array<string, mixed>|null $document */
    public function __construct(
        private readonly ?array $document = null,
        private readonly ?AgentIntegrationRegistry $integrations = null,
    ) {}

    /** @return array<string, mixed> */
    public function document(): array
    {
        if ($this->document !== null) {
            $document = $this->document;
            if (! isset($document['operations'])) {
                $document['operations'] = self::builtInRegistry()->document()['operations'] ?? [];
            }
            return $this->sanitize($document);
        }

        return $this->defaults();
    }

    /** @param array<string, mixed> $document @return array<string, mixed> */
    public function save(array $document): array
    {
        throw new \LogicException('Semantic routing is service-owned and read-only at runtime.');
    }

    /** @return list<array<string, mixed>> */
    public function globalGroups(): array
    {
        $groups = $this->document()['global'] ?? [];

        return is_array($groups) ? array_values($groups) : [];
    }

    /** @return list<array<string, mixed>> */
    public function moduleGroups(string $module): array
    {
        $modules = $this->document()['modules'] ?? [];
        $groups = is_array($modules) ? ($modules[$module] ?? []) : [];

        return is_array($groups) ? array_values($groups) : [];
    }

    /** @return array<string, mixed>|null */
    public function operation(string $ref): ?array
    {
        $operation = $this->document()['operations'][$ref] ?? null;
        return is_array($operation) ? $operation : null;
    }

    public function knownModule(string $module): bool
    {
        return array_key_exists($module, (array) ($this->document()['modules'] ?? []));
    }

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        $registry = $this->integrations ?? self::builtInRegistry();
        return $this->sanitize($registry->document());
    }

    public static function builtInRegistry(): AgentIntegrationRegistry
    {
        SeoOpsAgentIntegration::registerCapabilities();
        $registry = new AgentIntegrationRegistry();
        $root = dirname(__DIR__, 2);
        $manifest = json_decode((string) file_get_contents($root.'/addon.json'), true);
        if (is_array($manifest) && ($manifest['agent_enabled'] ?? false) === true) {
            $routing = (string) ($manifest['agent_routing'] ?? '');
            $cases = (string) ($manifest['agent_cases'] ?? '');
            if ($routing === '' || $cases === '') throw new \RuntimeException('Enabled Agent integration requires routing and cases paths.');
            $registry->register('seo-ops', $root.'/'.$routing, $root.'/'.$cases);
        }
        return $registry;
    }

    /** @return array{present: bool, revision: int|null, authoritative: false} */
    public function persistedCompatibilityStatus(): array
    {
        try {
            $saved = AgentApp::findByKey(self::APP_KEY)?->metadata[self::META_KEY] ?? null;
            return ['present' => is_array($saved), 'revision' => is_array($saved) ? (int) ($saved['revision'] ?? 1) : null, 'authoritative' => false];
        } catch (Throwable) {
            return ['present' => false, 'revision' => null, 'authoritative' => false];
        }
    }

    /** @param array<string, mixed> $document @return array<string, mixed> */
    public function sanitize(array $document): array
    {
        $modules = [];
        $incoming = is_array($document['modules'] ?? null) ? $document['modules'] : [];
        $operationRefs = array_keys(is_array($document['operations'] ?? null) ? $document['operations'] : []);
        foreach (array_keys($incoming) as $module) {
            $modules[$module] = $this->groups(is_array($incoming[$module] ?? null) ? $incoming[$module] : [], $operationRefs);
        }

        return [
            'revision' => max(1, (int) ($document['revision'] ?? 1)),
            'global' => $this->groups(is_array($document['global'] ?? null) ? $document['global'] : [], array_keys($incoming)),
            'modules' => $modules,
            'lexical_hints' => $this->lexicalHints(is_array($document['lexical_hints'] ?? null) ? $document['lexical_hints'] : [], array_keys($incoming)),
            'policy' => $this->policy(is_array($document['policy'] ?? null) ? $document['policy'] : []),
            'operations' => is_array($document['operations'] ?? null) ? $document['operations'] : [],
        ];
    }

    /** @param list<mixed> $hints @param list<string> $modules @return list<array<string, mixed>> */
    private function lexicalHints(array $hints, array $modules): array
    {
        $clean = [];
        foreach ($hints as $hint) {
            if (! is_array($hint) || ! in_array((string) ($hint['module'] ?? ''), $modules, true)) continue;
            $phrases = array_slice(array_values(array_unique(array_filter(array_map(
                static fn (mixed $value): string => trim((string) $value), (array) ($hint['phrases'] ?? [])
            )))), 0, 20);
            $id = trim((string) ($hint['id'] ?? ''));
            if ($id === '' || $phrases === []) continue;
            $clean[] = ['id' => substr($id, 0, 80), 'module' => (string) $hint['module'], 'phrases' => $phrases,
                'weight' => min(0.10, max(0.001, (float) ($hint['weight'] ?? 0.01))), 'enabled' => ($hint['enabled'] ?? true) !== false];
        }
        return $clean;
    }

    /** @param array<string, mixed> $policy @return array<string, mixed> */
    private function policy(array $policy): array
    {
        return [
            'min_semantic_candidate' => min(1.0, max(-1.0, (float) ($policy['min_semantic_candidate'] ?? 0.45))),
            'min_operation_score' => min(1.0, max(-1.0, (float) ($policy['min_operation_score'] ?? 0.62))),
            'final_margin' => min(2.0, max(0.0, (float) ($policy['final_margin'] ?? 0.08))),
            'global_coefficient' => min(1.0, max(0.0, (float) ($policy['global_coefficient'] ?? 0.35))),
            'internal_coefficient' => min(1.0, max(0.0, (float) ($policy['internal_coefficient'] ?? 0.65))),
            'lexical_ceiling' => min(0.10, max(0.0, (float) ($policy['lexical_ceiling'] ?? 0.10))),
            'max_modules' => min(3, max(1, (int) ($policy['max_modules'] ?? 3))),
        ];
    }

    /**
     * @param list<mixed> $groups
     * @param list<string> $validRefs
     * @return list<array<string, mixed>>
     */
    private function groups(array $groups, array $validRefs): array
    {
        $clean = [];
        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }
            $id = trim((string) ($group['id'] ?? ''));
            $examples = [];
            foreach ((array) ($group['examples'] ?? []) as $example) {
                $text = trim((string) $example);
                if ($text !== '' && strlen($text) <= 500) {
                    $examples[] = $text;
                }
            }
            $examples = array_slice(array_values(array_unique($examples)), 0, 12);
            $targets = [];
            foreach ((array) ($group['targets'] ?? []) as $target) {
                if (! is_array($target)) {
                    continue;
                }
                $ref = trim((string) ($target['ref'] ?? ''));
                if (! in_array($ref, $validRefs, true)) {
                    continue;
                }
                $weight = (int) ($target['weight'] ?? 0);
                if ($weight < 1 || $weight > 100) {
                    continue;
                }
                $targets[] = ['ref' => $ref, 'weight' => $weight];
            }
            if ($id === '' || $examples === [] || $targets === []) {
                continue;
            }
            $clean[] = [
                'id' => substr($id, 0, 80),
                'name' => substr(trim((string) ($group['name'] ?? $id)), 0, 160),
                'description' => substr(trim((string) ($group['description'] ?? '')), 0, 400),
                'examples' => $examples,
                'enabled' => ($group['enabled'] ?? true) !== false,
                'targets' => $targets,
            ];
        }

        return $clean;
    }

    /**
     * Merge newly shipped system groups/targets without replacing saved examples,
     * enabled flags, or user-defined weights.
     *
     * @param array<string, mixed> $saved
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    private function upgradePersisted(array $saved, array $defaults): array
    {
        if (! array_key_exists('lexical_hints', $saved)) {
            $saved['lexical_hints'] = $defaults['lexical_hints'] ?? [];
        } else {
            $known = array_column(array_filter((array) $saved['lexical_hints'], 'is_array'), null, 'id');
            foreach ((array) ($defaults['lexical_hints'] ?? []) as $hint) {
                if (! is_array($hint)) continue;
                $id = (string) ($hint['id'] ?? '');
                if (! isset($known[$id])) {
                    $saved['lexical_hints'][] = $hint;
                    continue;
                }
                foreach ($saved['lexical_hints'] as &$savedHint) {
                    if (is_array($savedHint) && ($savedHint['id'] ?? null) === $id) {
                        $savedHint['phrases'] = $this->repairSystemStrings(
                            (array) ($savedHint['phrases'] ?? []),
                            (array) ($hint['phrases'] ?? [])
                        );
                    }
                }
                unset($savedHint);
            }
        }
        $saved['policy'] = array_replace((array) ($defaults['policy'] ?? []), (array) ($saved['policy'] ?? []));
        $saved['global'] = $this->mergeSystemGroups((array) ($saved['global'] ?? []), (array) ($defaults['global'] ?? []));
        $savedModules = is_array($saved['modules'] ?? null) ? $saved['modules'] : [];
        foreach ((array) ($defaults['modules'] ?? []) as $module => $defaultGroups) {
            $savedModules[$module] = $this->mergeSystemGroups(
                (array) ($savedModules[$module] ?? []),
                (array) $defaultGroups
            );
        }
        $savedKeywords = (array) ($savedModules['keywords'] ?? []);

        foreach ($savedKeywords as &$group) {
            if (! is_array($group) || ($group['id'] ?? null) !== 'keywords_inventory') {
                continue;
            }
            $targets = is_array($group['targets'] ?? null) ? $group['targets'] : [];
            $hasInventory = array_filter($targets, static fn (mixed $target): bool => is_array($target) && ($target['ref'] ?? null) === 'keywords.inventory') !== [];
            if (! $hasInventory) {
                $targets[] = ['ref' => 'keywords.inventory', 'weight' => 100];
                $group['targets'] = $targets;
            }
        }
        unset($group);

        $savedModules['keywords'] = $savedKeywords;
        $saved['modules'] = $savedModules;

        return $saved;
    }

    /** @param list<mixed> $savedGroups @param list<mixed> $defaultGroups @return list<mixed> */
    private function mergeSystemGroups(array $savedGroups, array $defaultGroups): array
    {
        $savedGroups = array_values($savedGroups);
        $byId = [];
        foreach ($savedGroups as $index => $group) {
            if (is_array($group)) $byId[(string) ($group['id'] ?? '')] = $index;
        }
        foreach ($defaultGroups as $defaultGroup) {
            if (! is_array($defaultGroup)) continue;
            $id = (string) ($defaultGroup['id'] ?? '');
            if (! array_key_exists($id, $byId)) {
                $savedGroups[] = $defaultGroup;
                continue;
            }
            $index = $byId[$id];
            $savedGroups[$index]['examples'] = $this->repairSystemStrings(
                (array) ($savedGroups[$index]['examples'] ?? []),
                (array) ($defaultGroup['examples'] ?? [])
            );
        }

        return $savedGroups;
    }

    /** @param list<mixed> $saved @param list<mixed> $defaults @return list<mixed> */
    private function repairSystemStrings(array $saved, array $defaults): array
    {
        foreach ($saved as $index => $value) {
            if (! is_string($value) || ! isset($defaults[$index]) || ! is_string($defaults[$index])) continue;
            if (str_contains($value, "\u{FFFD}") || preg_match('/\pL\?\pL/u', $value) === 1) {
                $saved[$index] = $defaults[$index];
            }
        }

        return array_values($saved);
    }
}
