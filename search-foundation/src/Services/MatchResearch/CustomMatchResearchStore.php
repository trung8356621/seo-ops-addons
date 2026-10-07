<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services\MatchResearch;

use Illuminate\Support\Str;
use Omnichannel\Addons\SearchFoundation\DTO\MatchResearch\MatchResearchCapabilities;
use Omnichannel\Addons\SearchFoundation\DTO\MatchResearch\MatchResearchResource;
use Omnichannel\Addons\SearchFoundation\Enums\MatchResearchKind;
use Omnichannel\Addons\SearchFoundation\Enums\MatchResearchOrigin;
use Omnichannel\Addons\SearchFoundation\Models\MatchResearchCustomConcept;

final class CustomMatchResearchStore
{
    /** @return list<MatchResearchResource> */
    public function listForSite(int $siteId): array
    {
        if ($siteId <= 0) {
            return [];
        }

        return MatchResearchCustomConcept::query()
            ->where('site_id', $siteId)
            ->orderBy('name')
            ->get()
            ->map(fn (MatchResearchCustomConcept $row): MatchResearchResource => $this->toResource($row))
            ->all();
    }

    public function find(string $key, int $siteId): ?MatchResearchResource
    {
        if ($siteId <= 0 || ! str_starts_with($key, 'custom.')) {
            return null;
        }

        $row = MatchResearchCustomConcept::query()
            ->where('site_id', $siteId)
            ->where('resource_key', $key)
            ->first();

        return $row ? $this->toResource($row) : null;
    }

    /**
     * @param  array{
     *   name:string,
     *   description?:string|null,
     *   source_locale:string,
     *   positive_examples?:list<string>,
     *   negative_examples?:list<string>,
     *   enabled?:bool,
     *   resource_key?:string|null
     * }  $input
     */
    public function create(int $siteId, array $input): MatchResearchResource
    {
        if ($siteId <= 0) {
            throw new \InvalidArgumentException('site_id is required for custom concepts.');
        }

        $name = trim((string) ($input['name'] ?? ''));
        $sourceLocale = trim((string) ($input['source_locale'] ?? ''));
        if ($name === '' || $sourceLocale === '') {
            throw new \InvalidArgumentException('name and source_locale are required.');
        }

        $key = trim((string) ($input['resource_key'] ?? ''));
        if ($key === '') {
            $key = 'custom.'.$this->slugify($name);
        }
        if (! str_starts_with($key, 'custom.')) {
            $key = 'custom.'.$key;
        }
        $key = $this->ensureUniqueKey($siteId, $key);

        $row = MatchResearchCustomConcept::query()->create([
            'site_id' => $siteId,
            'resource_key' => $key,
            'source_locale' => $sourceLocale,
            'name' => $name,
            'description' => trim((string) ($input['description'] ?? '')),
            'positive_examples' => $this->normalizeList($input['positive_examples'] ?? []),
            'negative_examples' => $this->normalizeList($input['negative_examples'] ?? []),
            'enabled' => (bool) ($input['enabled'] ?? true),
        ]);

        return $this->toResource($row);
    }

    /**
     * @param  array{
     *   name?:string,
     *   description?:string|null,
     *   positive_examples?:list<string>,
     *   negative_examples?:list<string>,
     *   enabled?:bool
     * }  $input
     */
    public function update(string $key, int $siteId, array $input): MatchResearchResource
    {
        $row = MatchResearchCustomConcept::query()
            ->where('site_id', $siteId)
            ->where('resource_key', $key)
            ->firstOrFail();

        if (array_key_exists('name', $input)) {
            $name = trim((string) $input['name']);
            if ($name === '') {
                throw new \InvalidArgumentException('name cannot be empty.');
            }
            $row->name = $name;
        }
        if (array_key_exists('description', $input)) {
            $row->description = trim((string) $input['description']);
        }
        if (array_key_exists('positive_examples', $input)) {
            $row->positive_examples = $this->normalizeList($input['positive_examples']);
        }
        if (array_key_exists('negative_examples', $input)) {
            $row->negative_examples = $this->normalizeList($input['negative_examples']);
        }
        if (array_key_exists('enabled', $input)) {
            $row->enabled = (bool) $input['enabled'];
        }
        // source_locale and resource_key are immutable after create
        $row->save();

        return $this->toResource($row->fresh());
    }

    public function delete(string $key, int $siteId): void
    {
        if (! str_starts_with($key, 'custom.')) {
            throw new \InvalidArgumentException('Only custom resources can be deleted.');
        }

        MatchResearchCustomConcept::query()
            ->where('site_id', $siteId)
            ->where('resource_key', $key)
            ->delete();
    }

    private function toResource(MatchResearchCustomConcept $row): MatchResearchResource
    {
        return new MatchResearchResource(
            key: (string) $row->resource_key,
            origin: MatchResearchOrigin::Custom,
            kind: MatchResearchKind::Concept,
            sourceLocale: (string) $row->source_locale,
            label: (string) $row->name,
            description: (string) ($row->description ?? ''),
            matchMode: null,
            payload: [
                'name' => (string) $row->name,
                'description' => (string) ($row->description ?? ''),
                'aliases' => [],
                'positive_examples' => array_values((array) $row->positive_examples),
                'negative_examples' => array_values((array) $row->negative_examples),
            ],
            // Knowledge only in V1 — no deterministic/semantic auto-match runtime yet.
            capabilities: new MatchResearchCapabilities(
                canMatch: false,
                canTag: true,
                canExclude: true,
                canRank: false,
            ),
            editable: true,
            deletable: true,
            provenance: ['site_id' => (int) $row->site_id],
            siteId: (int) $row->site_id,
            enabled: (bool) $row->enabled,
        );
    }

    private function slugify(string $name): string
    {
        $slug = Str::slug($name, '_');
        if ($slug === '') {
            $slug = 'concept_'.substr(hash('sha256', $name), 0, 8);
        }

        return $slug;
    }

    private function ensureUniqueKey(int $siteId, string $key): string
    {
        $base = $key;
        $i = 2;
        while (MatchResearchCustomConcept::query()->where('site_id', $siteId)->where('resource_key', $key)->exists()) {
            $key = $base.'_'.$i;
            $i++;
        }

        return $key;
    }

    /** @return list<string> */
    private function normalizeList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($raw as $item) {
            $value = trim((string) $item);
            if ($value === '') {
                continue;
            }
            $dedupe = mb_strtolower($value, 'UTF-8');
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;
            $out[] = $value;
        }

        return $out;
    }
}
