<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Routing;

use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentApp;
use Throwable;

final class SemanticRoutingConfig
{
    public const APP_KEY = 'seo-ops';

    public const META_KEY = 'semantic_routing';

    /** @param array<string, mixed>|null $document */
    public function __construct(private readonly ?array $document = null) {}

    /** @return array<string, mixed> */
    public function document(): array
    {
        if ($this->document !== null) {
            return $this->sanitize($this->document);
        }

        $defaults = $this->defaults();
        try {
            $app = AgentApp::findByKey(self::APP_KEY);
            $saved = $app?->metadata[self::META_KEY] ?? null;
            if (! is_array($saved)) {
                return $defaults;
            }

            return $this->sanitize($this->upgradePersisted($saved, $defaults));
        } catch (Throwable) {
            return $defaults;
        }
    }

    /** @param array<string, mixed> $document @return array<string, mixed> */
    public function save(array $document): array
    {
        $clean = $this->sanitize($document);
        $clean['revision'] = ((int) ($this->document()['revision'] ?? 1)) + 1;
        $app = AgentApp::findByKey(self::APP_KEY);
        if ($app === null) {
            throw new \RuntimeException('SEO Ops agent app is not installed.');
        }
        $metadata = is_array($app->metadata) ? $app->metadata : [];
        $metadata[self::META_KEY] = $clean;
        $app->metadata = $metadata;
        $app->save();

        return $clean;
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

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        $path = dirname(__DIR__, 2).'/resources/semantic-routing.default.json';
        $decoded = json_decode((string) file_get_contents($path), true);

        return $this->sanitize(is_array($decoded) ? $decoded : []);
    }

    /** @param array<string, mixed> $document @return array<string, mixed> */
    public function sanitize(array $document): array
    {
        $modules = [];
        $incoming = is_array($document['modules'] ?? null) ? $document['modules'] : [];
        foreach (SemanticOperationRegistry::moduleKeys() as $module) {
            $modules[$module] = $this->groups(is_array($incoming[$module] ?? null) ? $incoming[$module] : [], false);
        }

        return [
            'revision' => max(1, (int) ($document['revision'] ?? 1)),
            'global' => $this->groups(is_array($document['global'] ?? null) ? $document['global'] : [], true),
            'modules' => $modules,
        ];
    }

    /**
     * @param list<mixed> $groups
     * @return list<array<string, mixed>>
     */
    private function groups(array $groups, bool $moduleScope): array
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
                $known = $moduleScope
                    ? SemanticOperationRegistry::knownModule($ref)
                    : SemanticOperationRegistry::knownOperation($ref);
                if (! $known) {
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
        $savedModules = is_array($saved['modules'] ?? null) ? $saved['modules'] : [];
        $defaultKeywords = is_array($defaults['modules']['keywords'] ?? null) ? $defaults['modules']['keywords'] : [];
        $savedKeywords = is_array($savedModules['keywords'] ?? null) ? array_values($savedModules['keywords']) : [];

        $byId = [];
        foreach ($savedKeywords as $index => $group) {
            if (is_array($group)) {
                $byId[(string) ($group['id'] ?? '')] = $index;
            }
        }
        foreach ($defaultKeywords as $defaultGroup) {
            if (! is_array($defaultGroup)) {
                continue;
            }
            $id = (string) ($defaultGroup['id'] ?? '');
            if (! array_key_exists($id, $byId)) {
                $savedKeywords[] = $defaultGroup;
            }
        }

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
}
