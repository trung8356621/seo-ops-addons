<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Catalog;

use Omnichannel\Addons\Content\Models\SeoArticle;

final class AgentTestArticleCatalogService
{
    /** @return list<array{id: int, title: string}> */
    public function search(int $ownerId, int $siteId, string $search): array
    {
        $search = trim($search);

        return SeoArticle::query()
            ->select(['id', 'title'])
            ->where('user_id', $ownerId)
            ->where('site_id', $siteId)
            ->when($search !== '', static function ($query) use ($search): void {
                $query->where('title', 'like', '%'.self::escapeLike($search).'%');
            })
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(static fn (SeoArticle $article): array => [
                'id' => (int) $article->id,
                'title' => (string) $article->title,
            ])
            ->all();
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
