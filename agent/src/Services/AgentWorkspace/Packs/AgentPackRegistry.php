<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Packs;

use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentPack;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentPackRevision;
use Illuminate\Support\Str;
use Throwable;

/**
 * In-memory + DB pack registry. Invalid pack isolated — never breaks workspace.
 */
final class AgentPackRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $enabledCompiled = [];

    private bool $booted = false;

    public function __construct(
        private readonly AgentPackCache $cache = new AgentPackCache,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function enabledSkillDefinitions(): array
    {
        $this->boot();
        $skills = [];
        $keys = array_keys($this->enabledCompiled);
        sort($keys);
        foreach ($keys as $packKey) {
            $compiled = $this->enabledCompiled[$packKey];
            foreach ($compiled['skills'] ?? [] as $skill) {
                if (is_array($skill)) {
                    $skills[] = $skill;
                }
            }
        }

        return $skills;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function enabledTemplateDefinitions(): array
    {
        $this->boot();
        $templates = [];
        $keys = array_keys($this->enabledCompiled);
        sort($keys);
        foreach ($keys as $packKey) {
            $compiled = $this->enabledCompiled[$packKey];
            foreach ($compiled['templates'] ?? [] as $tpl) {
                if (is_array($tpl)) {
                    $templates[] = $this->toChatTemplateRow($tpl);
                }
            }
        }

        return $templates;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSummaries(): array
    {
        try {
            return SeoAgentPack::query()
                ->orderBy('key')
                ->get()
                ->map(function (SeoAgentPack $pack): array {
                    $skillCount = 0;
                    if ($pack->active_revision_id) {
                        $rev = SeoAgentPackRevision::query()->find($pack->active_revision_id);
                        $compiled = is_array($rev?->compiled_json) ? $rev->compiled_json : [];
                        $skillCount = count($compiled['skills'] ?? []);
                    }

                    return [
                        'hash_id' => $pack->hash_id,
                        'key' => $pack->key,
                        'name' => $pack->name,
                        'version' => $pack->version,
                        'type' => $pack->type,
                        'source' => $pack->source,
                        'trust' => $pack->trust,
                        'status' => $pack->status,
                        'compatibility' => $pack->compatibility,
                        'health' => $pack->health,
                        'skill_count' => $skillCount,
                        'gate_status' => null,
                    ];
                })->all();
        } catch (Throwable) {
            return [];
        }
    }

    public function invalidate(): void
    {
        $this->booted = false;
        $this->enabledCompiled = [];
    }

    /**
     * Register compiled pack into runtime (enabled only).
     *
     * @param  array<string, mixed>  $compiled
     */
    public function putEnabled(string $packKey, string $revisionHash, array $compiled): void
    {
        $this->enabledCompiled[$packKey] = $compiled;
        $this->cache->put($packKey, $revisionHash, $compiled);
        $this->cache->rememberIndex($packKey, $revisionHash);
        $this->booted = true;
    }

    public function removeEnabled(string $packKey): void
    {
        unset($this->enabledCompiled[$packKey]);
        $this->cache->forgetPack($packKey);
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        try {
            $packs = SeoAgentPack::query()->where('status', 'enabled')->orderBy('key')->get();
            foreach ($packs as $pack) {
                try {
                    if (! $pack->active_revision_id) {
                        continue;
                    }
                    $rev = SeoAgentPackRevision::query()->find($pack->active_revision_id);
                    if ($rev === null || $rev->status !== 'active') {
                        continue;
                    }
                    $compiled = is_array($rev->compiled_json) ? $rev->compiled_json : null;
                    if ($compiled === null) {
                        continue;
                    }
                    $cached = $this->cache->get((string) $pack->key, (string) $rev->definition_hash);
                    $this->enabledCompiled[(string) $pack->key] = $cached ?? $compiled;
                } catch (Throwable) {
                    // isolate invalid pack
                    continue;
                }
            }
        } catch (Throwable) {
            $this->enabledCompiled = [];
        }
    }

    /**
     * @param  array<string, mixed>  $tpl
     * @return array<string, mixed>
     */
    private function toChatTemplateRow(array $tpl): array
    {
        return [
            'key' => (string) ($tpl['key'] ?? ''),
            'title' => (string) ($tpl['name'] ?? $tpl['title'] ?? ''),
            'description' => (string) ($tpl['description'] ?? ''),
            'prompt_template' => (string) ($tpl['draft'] ?? $tpl['prompt_template'] ?? ''),
            'skill_key' => (string) ($tpl['open_skill'] ?? $tpl['skill_key'] ?? ''),
            'variables' => is_array($tpl['variables'] ?? null) ? $tpl['variables'] : [],
            'sort_order' => (int) ($tpl['sort_order'] ?? 500),
            'is_featured' => (bool) ($tpl['is_featured'] ?? false),
            'category' => 'pack',
        ];
    }

    public static function newHashId(string $prefix = 'apack'): string
    {
        return $prefix.'_'.Str::lower((string) Str::ulid());
    }
}
