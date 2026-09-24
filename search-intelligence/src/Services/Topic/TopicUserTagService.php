<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicTag;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicTagAssignment;
use Throwable;

/**
 * Site-scoped Topic custom tags.
 *
 * Allowed: create, attach, detach, delete.
 * Forbidden: rename/update tag metadata.
 * Tag-only mutations do NOT promote Topic auto→manual.
 * Recluster must not rewrite vocabulary; only dissolve may drop assignments.
 */
final class TopicUserTagService
{
    public static function tablesReady(): bool
    {
        $schema = Schema::connection('omi_seo_ai');

        return $schema->hasTable('seo_topics')
            && $schema->hasTable('seo_topic_tags')
            && $schema->hasTable('seo_topic_tag_assignments')
            && $schema->hasColumn('seo_topic_tags', 'site_id')
            && $schema->hasColumn('seo_topic_tags', 'name');
    }

    public static function provenanceReady(): bool
    {
        return self::tablesReady()
            && Schema::connection('omi_seo_ai')->hasColumn('seo_topic_tag_assignments', 'source');
    }

    /**
     * @return list<array{id: int, name: string, slug: string, topic_count: int}>
     */
    public function listForSite(int $siteId, ?string $search = null, int $limit = 200): array
    {
        if ($siteId <= 0 || ! self::tablesReady()) {
            return [];
        }

        $query = SeoTopicTag::query()
            ->where('site_id', $siteId)
            ->orderBy('name');

        $needle = trim((string) $search);
        if ($needle !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($needle)).'%';
            $query->whereRaw('LOWER(name) LIKE ?', [$like]);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $tags = $query->get(['id', 'name', 'slug']);
        if ($tags->isEmpty()) {
            return [];
        }

        $counts = SeoTopicTagAssignment::query()
            ->whereIn('tag_id', $tags->pluck('id')->all())
            ->selectRaw('tag_id, COUNT(*) as topic_count')
            ->groupBy('tag_id')
            ->pluck('topic_count', 'tag_id');

        return $tags->map(static function (SeoTopicTag $tag) use ($counts): array {
            return [
                'id' => (int) $tag->id,
                'name' => (string) $tag->name,
                'slug' => (string) $tag->slug,
                'topic_count' => (int) ($counts[(int) $tag->id] ?? 0),
            ];
        })->values()->all();
    }

    public function countForSite(int $siteId): int
    {
        if ($siteId <= 0 || ! self::tablesReady()) {
            return 0;
        }

        return (int) SeoTopicTag::query()->where('site_id', $siteId)->count();
    }

    /**
     * Autocomplete for current site only.
     *
     * @return list<array{id: int, name: string}>
     */
    public function search(int $siteId, string $query, int $limit = 20): array
    {
        $rows = $this->listForSite($siteId, $query, max(1, $limit));

        return array_map(
            static fn (array $row): array => [
                'id' => $row['id'],
                'name' => $row['name'],
            ],
            $rows,
        );
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function listForTopic(int $siteId, int $topicId): array
    {
        if ($siteId <= 0 || $topicId <= 0 || ! self::tablesReady()) {
            return [];
        }
        if (! $this->topicBelongsToSite($siteId, $topicId)) {
            return [];
        }

        $tagIds = SeoTopicTagAssignment::query()
            ->where('topic_id', $topicId)
            ->pluck('tag_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        if ($tagIds === []) {
            return [];
        }

        return SeoTopicTag::query()
            ->where('site_id', $siteId)
            ->whereIn('id', $tagIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (SeoTopicTag $tag): array => [
                'id' => (int) $tag->id,
                'name' => (string) $tag->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $topicIds
     * @return array<int, list<array{id: int, name: string}>>
     */
    public function mapForTopics(int $siteId, array $topicIds): array
    {
        $topicIds = array_values(array_unique(array_filter(
            array_map('intval', $topicIds),
            static fn (int $id): bool => $id > 0,
        )));
        /** @var array<int, list<array{id: int, name: string}>> $out */
        $out = [];
        foreach ($topicIds as $topicId) {
            $out[$topicId] = [];
        }
        if ($siteId <= 0 || $topicIds === [] || ! self::tablesReady()) {
            return $out;
        }

        $allowed = SeoTopic::query()
            ->where('site_id', $siteId)
            ->whereIn('id', $topicIds)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        if ($allowed === []) {
            return $out;
        }

        $rows = SeoTopicTagAssignment::query()
            ->whereIn('topic_id', $allowed)
            ->get(['topic_id', 'tag_id']);
        $tagIds = $rows->pluck('tag_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        if ($tagIds === []) {
            return $out;
        }

        /** @var array<int, string> $names */
        $names = SeoTopicTag::query()
            ->where('site_id', $siteId)
            ->whereIn('id', $tagIds)
            ->pluck('name', 'id')
            ->mapWithKeys(static fn ($name, $id): array => [(int) $id => (string) $name])
            ->all();

        foreach ($rows as $row) {
            $topicId = (int) $row->topic_id;
            $tagId = (int) $row->tag_id;
            $name = $names[$tagId] ?? '';
            if ($name === '') {
                continue;
            }
            $out[$topicId][] = ['id' => $tagId, 'name' => $name];
        }

        foreach ($out as $topicId => $tags) {
            usort($tags, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
            $out[$topicId] = array_values($tags);
        }

        return $out;
    }

    /**
     * Create-or-reuse by (site_id, slug). Never renames existing rows.
     *
     * @return array{ok: bool, error: ?string, tag: ?array{id: int, name: string, slug: string}}
     */
    public function findOrCreate(int $siteId, string $name): array
    {
        if ($siteId <= 0 || ! self::tablesReady()) {
            return ['ok' => false, 'error' => 'invalid_args', 'tag' => null];
        }

        $normalized = $this->normalizeName($name);
        if ($normalized === '') {
            return ['ok' => false, 'error' => 'invalid_args', 'tag' => null];
        }

        $slug = $this->slugFor($normalized);
        $existing = SeoTopicTag::query()
            ->where('site_id', $siteId)
            ->where('slug', $slug)
            ->first();
        if ($existing instanceof SeoTopicTag) {
            return [
                'ok' => true,
                'error' => null,
                'tag' => [
                    'id' => (int) $existing->id,
                    'name' => (string) $existing->name,
                    'slug' => (string) $existing->slug,
                ],
            ];
        }

        try {
            $created = SeoTopicTag::query()->create([
                'site_id' => $siteId,
                'name' => $normalized,
                'slug' => $slug,
            ]);
        } catch (QueryException $e) {
            // Race on UNIQUE(site_id, slug) — reuse winner.
            $existing = SeoTopicTag::query()
                ->where('site_id', $siteId)
                ->where('slug', $slug)
                ->first();
            if (! $existing instanceof SeoTopicTag) {
                return ['ok' => false, 'error' => 'create_failed', 'tag' => null];
            }

            return [
                'ok' => true,
                'error' => null,
                'tag' => [
                    'id' => (int) $existing->id,
                    'name' => (string) $existing->name,
                    'slug' => (string) $existing->slug,
                ],
            ];
        }

        return [
            'ok' => true,
            'error' => null,
            'tag' => [
                'id' => (int) $created->id,
                'name' => (string) $created->name,
                'slug' => (string) $created->slug,
            ],
        ];
    }

    /**
     * @return array{ok: bool, error: ?string, tags: list<array{id: int, name: string}>}
     */
    public function attach(int $siteId, int $topicId, int $tagId): array
    {
        if ($siteId <= 0 || $topicId <= 0 || $tagId <= 0) {
            return ['ok' => false, 'error' => 'invalid_args', 'tags' => []];
        }
        if (! self::tablesReady()) {
            return ['ok' => false, 'error' => 'tables_missing', 'tags' => []];
        }
        if (! $this->topicBelongsToSite($siteId, $topicId)) {
            return ['ok' => false, 'error' => 'topic_not_found', 'tags' => []];
        }

        $tag = SeoTopicTag::query()->where('id', $tagId)->first();
        if (! $tag instanceof SeoTopicTag) {
            return ['ok' => false, 'error' => 'tag_not_found', 'tags' => []];
        }
        if ((int) $tag->site_id !== $siteId) {
            return ['ok' => false, 'error' => 'site_mismatch', 'tags' => []];
        }

        $this->attachManualPair($topicId, $tagId);

        // Tag-only metadata — do NOT promote Topic auto→manual / do NOT promote auto→manual.

        return ['ok' => true, 'error' => null, 'tags' => $this->listForTopic($siteId, $topicId)];
    }

    /**
     * Manual attach: source=manual. If an AI pair exists, promote to manual.
     */
    public function attachManualPair(int $topicId, int $tagId): void
    {
        $existing = SeoTopicTagAssignment::query()
            ->where('topic_id', $topicId)
            ->where('tag_id', $tagId)
            ->first();

        if ($existing instanceof SeoTopicTagAssignment) {
            if (self::provenanceReady()
                && TopicTagAssignmentSource::isAi((string) ($existing->source ?? ''))
            ) {
                $existing->source = TopicTagAssignmentSource::MANUAL;
                $existing->save();
            }

            return;
        }

        $attrs = [
            'topic_id' => $topicId,
            'tag_id' => $tagId,
        ];
        if (self::provenanceReady()) {
            $attrs['source'] = TopicTagAssignmentSource::MANUAL;
        }
        SeoTopicTagAssignment::query()->create($attrs);
    }

    /**
     * Apply validated AI tag_suggestions. Preserves manual assignments.
     * Removes obsolete AI-only pairs. Never mutates Topic source / membership.
     *
     * @param  list<array{name: string}>  $taxonomy
     * @param  list<array{topic_id: int, tags: list<string>}>  $assignments
     * @return array{
     *   ok: bool,
     *   error: ?string,
     *   tag_count: int,
     *   topic_tagged_count: int,
     *   ai_assignment_count: int,
     *   untagged_topics: int
     * }
     */
    public function syncAiSuggestions(int $siteId, array $taxonomy, array $assignments, int $totalTopics = 0): array
    {
        $empty = [
            'ok' => false,
            'error' => 'invalid_args',
            'tag_count' => 0,
            'topic_tagged_count' => 0,
            'ai_assignment_count' => 0,
            'untagged_topics' => 0,
        ];
        if ($siteId <= 0 || ! self::tablesReady()) {
            return $empty;
        }

        try {
            return DB::connection('omi_seo_ai')->transaction(function () use ($siteId, $taxonomy, $assignments, $totalTopics): array {
                /** @var array<string, array{id: int, name: string, slug: string}> $resolvedBySlug */
                $resolvedBySlug = [];

                foreach ($taxonomy as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $name = $this->normalizeName((string) ($row['name'] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    $created = $this->findOrCreate($siteId, $name);
                    if (! ($created['ok'] ?? false) || $created['tag'] === null) {
                        continue;
                    }
                    $slug = (string) $created['tag']['slug'];
                    $resolvedBySlug[$slug] = $created['tag'];
                }

                /** @var array<int, array<int, true>> $desiredAiPairs topic_id => [tag_id => true] */
                $desiredAiPairs = [];
                foreach ($assignments as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $topicId = (int) ($row['topic_id'] ?? 0);
                    if ($topicId <= 0 || ! $this->topicBelongsToSite($siteId, $topicId)) {
                        continue;
                    }
                    $tagNames = is_array($row['tags'] ?? null) ? $row['tags'] : [];
                    foreach ($tagNames as $tagName) {
                        $name = $this->normalizeName((string) $tagName);
                        if ($name === '') {
                            continue;
                        }
                        $slug = $this->slugFor($name);
                        if (! isset($resolvedBySlug[$slug])) {
                            $created = $this->findOrCreate($siteId, $name);
                            if (! ($created['ok'] ?? false) || $created['tag'] === null) {
                                continue;
                            }
                            $resolvedBySlug[$slug] = $created['tag'];
                        }
                        $tagId = (int) $resolvedBySlug[$slug]['id'];
                        $desiredAiPairs[$topicId][$tagId] = true;
                    }
                }

                $siteTopicIds = SeoTopic::query()
                    ->where('site_id', $siteId)
                    ->pluck('id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();

                $provenance = self::provenanceReady();
                $existing = SeoTopicTagAssignment::query()
                    ->whereIn('topic_id', $siteTopicIds !== [] ? $siteTopicIds : [0])
                    ->get($provenance ? ['topic_id', 'tag_id', 'source'] : ['topic_id', 'tag_id']);

                /** @var array<string, SeoTopicTagAssignment> $byPair */
                $byPair = [];
                foreach ($existing as $row) {
                    $byPair[((int) $row->topic_id).':'.((int) $row->tag_id)] = $row;
                }

                // Remove obsolete AI assignments (never touch manual).
                foreach ($existing as $row) {
                    $topicId = (int) $row->topic_id;
                    $tagId = (int) $row->tag_id;
                    $source = $provenance
                        ? TopicTagAssignmentSource::normalize((string) ($row->source ?? TopicTagAssignmentSource::MANUAL))
                        : TopicTagAssignmentSource::MANUAL;
                    if ($source !== TopicTagAssignmentSource::AI) {
                        continue;
                    }
                    if (isset($desiredAiPairs[$topicId][$tagId])) {
                        continue;
                    }
                    SeoTopicTagAssignment::query()
                        ->where('topic_id', $topicId)
                        ->where('tag_id', $tagId)
                        ->delete();
                    unset($byPair[$topicId.':'.$tagId]);
                }

                // Upsert desired AI pairs without demoting manual.
                foreach ($desiredAiPairs as $topicId => $tagIds) {
                    foreach (array_keys($tagIds) as $tagId) {
                        $key = $topicId.':'.$tagId;
                        if (isset($byPair[$key])) {
                            $existingSource = $provenance
                                ? TopicTagAssignmentSource::normalize((string) ($byPair[$key]->source ?? ''))
                                : TopicTagAssignmentSource::MANUAL;
                            if ($existingSource === TopicTagAssignmentSource::MANUAL) {
                                continue;
                            }
                            if ($provenance && $existingSource !== TopicTagAssignmentSource::AI) {
                                $byPair[$key]->source = TopicTagAssignmentSource::AI;
                                $byPair[$key]->save();
                            }

                            continue;
                        }
                        $attrs = [
                            'topic_id' => $topicId,
                            'tag_id' => $tagId,
                        ];
                        if ($provenance) {
                            $attrs['source'] = TopicTagAssignmentSource::AI;
                        }
                        $created = SeoTopicTagAssignment::query()->create($attrs);
                        $byPair[$key] = $created;
                    }
                }

                $vocabCount = (int) SeoTopicTag::query()->where('site_id', $siteId)->count();
                $aiCount = 0;
                $taggedTopics = [];
                $allAssignments = SeoTopicTagAssignment::query()
                    ->whereIn('topic_id', $siteTopicIds !== [] ? $siteTopicIds : [0])
                    ->get($provenance ? ['topic_id', 'tag_id', 'source'] : ['topic_id', 'tag_id']);
                foreach ($allAssignments as $row) {
                    $taggedTopics[(int) $row->topic_id] = true;
                    if ($provenance && TopicTagAssignmentSource::isAi((string) ($row->source ?? ''))) {
                        $aiCount++;
                    }
                }
                $topicTaggedCount = count($taggedTopics);
                $topicTotal = $totalTopics > 0 ? $totalTopics : count($siteTopicIds);

                return [
                    'ok' => true,
                    'error' => null,
                    'tag_count' => $vocabCount,
                    'topic_tagged_count' => $topicTaggedCount,
                    'ai_assignment_count' => $provenance ? $aiCount : 0,
                    'untagged_topics' => max(0, $topicTotal - $topicTaggedCount),
                ];
            });
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'tag_count' => 0,
                'topic_tagged_count' => 0,
                'ai_assignment_count' => 0,
                'untagged_topics' => 0,
            ];
        }
    }

    /**
     * @return array{ok: bool, error: ?string, tags: list<array{id: int, name: string}>}
     */
    public function attachByName(int $siteId, int $topicId, string $name): array
    {
        $created = $this->findOrCreate($siteId, $name);
        if (! ($created['ok'] ?? false) || $created['tag'] === null) {
            return ['ok' => false, 'error' => (string) ($created['error'] ?? 'create_failed'), 'tags' => []];
        }

        return $this->attach($siteId, $topicId, (int) $created['tag']['id']);
    }

    /**
     * @return array{ok: bool, error: ?string, tags: list<array{id: int, name: string}>}
     */
    public function detach(int $siteId, int $topicId, int $tagId): array
    {
        if ($siteId <= 0 || $topicId <= 0 || $tagId <= 0) {
            return ['ok' => false, 'error' => 'invalid_args', 'tags' => []];
        }
        if (! self::tablesReady()) {
            return ['ok' => false, 'error' => 'tables_missing', 'tags' => []];
        }
        if (! $this->topicBelongsToSite($siteId, $topicId)) {
            return ['ok' => false, 'error' => 'topic_not_found', 'tags' => []];
        }

        $tag = SeoTopicTag::query()->where('id', $tagId)->first();
        if ($tag instanceof SeoTopicTag && (int) $tag->site_id !== $siteId) {
            return ['ok' => false, 'error' => 'site_mismatch', 'tags' => []];
        }

        SeoTopicTagAssignment::query()
            ->where('topic_id', $topicId)
            ->where('tag_id', $tagId)
            ->delete();

        return ['ok' => true, 'error' => null, 'tags' => $this->listForTopic($siteId, $topicId)];
    }

    /**
     * Delete custom tag: detach all assignments, then delete vocabulary row.
     * Does not delete Topics / change source / recluster.
     *
     * @return array{ok: bool, error: ?string, detached: int}
     */
    public function deleteTag(int $siteId, int $tagId): array
    {
        if ($siteId <= 0 || $tagId <= 0 || ! self::tablesReady()) {
            return ['ok' => false, 'error' => 'invalid_args', 'detached' => 0];
        }

        $tag = SeoTopicTag::query()
            ->where('site_id', $siteId)
            ->where('id', $tagId)
            ->first();
        if (! $tag instanceof SeoTopicTag) {
            return ['ok' => false, 'error' => 'tag_not_found', 'detached' => 0];
        }

        $detached = SeoTopicTagAssignment::query()->where('tag_id', $tagId)->delete();
        SeoTopicTag::query()->where('site_id', $siteId)->where('id', $tagId)->delete();

        return ['ok' => true, 'error' => null, 'detached' => (int) $detached];
    }

    /**
     * @param  list<int>  $topicIds
     */
    public function deleteAssignmentsForTopics(array $topicIds): void
    {
        if (! self::tablesReady()) {
            return;
        }
        $topicIds = array_values(array_unique(array_filter(
            array_map('intval', $topicIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($topicIds === []) {
            return;
        }

        SeoTopicTagAssignment::query()->whereIn('topic_id', $topicIds)->delete();
    }

    /** @deprecated BC alias used by dissolve/recluster callers */
    public function deleteForTopics(array $topicIds): void
    {
        $this->deleteAssignmentsForTopics($topicIds);
    }

    public function normalizeName(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    }

    public function slugFor(string $normalizedName): string
    {
        $slug = Str::slug(mb_strtolower($normalizedName), '-');
        if ($slug === '') {
            $slug = 'tag-'.substr(sha1($normalizedName), 0, 8);
        }

        return $slug;
    }

    private function topicBelongsToSite(int $siteId, int $topicId): bool
    {
        return SeoTopic::query()
            ->where('site_id', $siteId)
            ->where('id', $topicId)
            ->exists();
    }
}
