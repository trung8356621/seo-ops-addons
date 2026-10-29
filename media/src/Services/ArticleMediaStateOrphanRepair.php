<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\Media\Models\ArticleMediaState;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Illuminate\Support\Facades\Schema;

/**
 * Null dangling article_media_states.media_id while keeping display_url.
 *
 * Intended for local/admin repair of historical rows (e.g. Archive #108 leftovers).
 * Do NOT run against production without an explicit operator request.
 */
final class ArticleMediaStateOrphanRepair
{
    /**
     * @param  list<int>  $articleIds  Empty = scan all states with media_id set (bounded).
     * @return array{examined: int, repaired: int, article_ids: list<int>}
     */
    public function nullMissingMediaIds(array $articleIds = [], int $limit = 500): array
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('article_media_states')) {
            return ['examined' => 0, 'repaired' => 0, 'article_ids' => []];
        }

        $query = ArticleMediaState::query()
            ->whereNotNull('media_id')
            ->where('media_id', '>', 0)
            ->orderBy('id')
            ->limit(max(1, $limit));

        if ($articleIds !== []) {
            $query->whereIn('article_id', array_values(array_filter(
                array_map(static fn ($id): int => (int) $id, $articleIds),
                static fn (int $id): bool => $id > 0,
            )));
        }

        $states = $query->get(['id', 'article_id', 'media_id', 'display_url']);
        if ($states->isEmpty()) {
            return ['examined' => 0, 'repaired' => 0, 'article_ids' => []];
        }

        $mediaIds = $states->pluck('media_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        $existing = SeoMedia::query()->whereIn('id', $mediaIds)->pluck('id')
            ->map(static fn ($id): int => (int) $id)->all();
        $existingSet = array_fill_keys($existing, true);

        $orphanIds = [];
        $touchedArticles = [];
        foreach ($states as $state) {
            $mediaId = (int) ($state->media_id ?? 0);
            if ($mediaId <= 0 || isset($existingSet[$mediaId])) {
                continue;
            }
            $orphanIds[] = (int) $state->id;
            $aid = (int) ($state->article_id ?? 0);
            if ($aid > 0) {
                $touchedArticles[$aid] = true;
            }
        }

        if ($orphanIds !== []) {
            ArticleMediaState::query()->whereIn('id', $orphanIds)->update(['media_id' => null]);
        }

        return [
            'examined' => $states->count(),
            'repaired' => count($orphanIds),
            'article_ids' => array_map('intval', array_keys($touchedArticles)),
        ];
    }
}
