<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicTag;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicTagAssignment;

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

        SeoTopicTagAssignment::query()->firstOrCreate([
            'topic_id' => $topicId,
            'tag_id' => $tagId,
        ]);

        // Tag-only metadata — do NOT promote auto→manual.

        return ['ok' => true, 'error' => null, 'tags' => $this->listForTopic($siteId, $topicId)];
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
