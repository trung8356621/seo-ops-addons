<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Tag;
use Omnichannel\Addons\SearchFoundation\Services\TagPersistenceService;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopic;
use Omnichannel\Addons\SearchIntelligence\Models\SeoTopicTag;

/**
 * Attach/detach user Tags on Topics.
 *
 * Reuses keyword_tags vocabulary. Tag-only edits do NOT convert auto→manual.
 * Recluster must not call this service.
 */
final class TopicUserTagService
{
    public function __construct(
        private readonly TagPersistenceService $tags,
    ) {}

    public static function tablesReady(): bool
    {
        $schema = Schema::connection('omi_seo_ai');

        return $schema->hasTable('seo_topics')
            && $schema->hasTable('seo_topic_tags')
            && $schema->hasTable('keyword_tags');
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

        $tagIds = SeoTopicTag::query()
            ->where('topic_id', $topicId)
            ->pluck('tag_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();
        if ($tagIds === []) {
            return [];
        }

        return Tag::query()
            ->whereIn('id', $tagIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (Tag $tag): array => [
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

        $rows = SeoTopicTag::query()
            ->whereIn('topic_id', $allowed)
            ->get(['topic_id', 'tag_id']);
        $tagIds = $rows->pluck('tag_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        if ($tagIds === []) {
            return $out;
        }

        /** @var array<int, string> $names */
        $names = Tag::query()
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
        if (! Tag::query()->where('id', $tagId)->exists()) {
            return ['ok' => false, 'error' => 'tag_not_found', 'tags' => []];
        }

        SeoTopicTag::query()->firstOrCreate([
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
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'error' => 'invalid_args', 'tags' => []];
        }
        $tag = $this->tags->findOrCreate($name);

        return $this->attach($siteId, $topicId, (int) $tag->getKey());
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

        SeoTopicTag::query()
            ->where('topic_id', $topicId)
            ->where('tag_id', $tagId)
            ->delete();

        return ['ok' => true, 'error' => null, 'tags' => $this->listForTopic($siteId, $topicId)];
    }

    /**
     * Drop attachments when Topics are dissolved (Recluster dissolve path).
     *
     * @param  list<int>  $topicIds
     */
    public function deleteForTopics(array $topicIds): void
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

        SeoTopicTag::query()->whereIn('topic_id', $topicIds)->delete();
    }

    private function topicBelongsToSite(int $siteId, int $topicId): bool
    {
        return SeoTopic::query()
            ->where('site_id', $siteId)
            ->where('id', $topicId)
            ->exists();
    }
}
