<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectWriterAssignment;

final class SeoProjectArticleOwnerSyncService
{
    /**
     * Gán owner (user_id) của mọi bài viết liên kết task về Assign writer của project.
     */
    public function syncProjectArticles(SeoProject $project): int
    {
        if (ContentProjectWriterAssignment::isUnassigned($project)) {
            return 0;
        }

        $writerId = (int) ($project->user_id ?? 0);
        if ($writerId <= 0) {
            return 0;
        }

        $articleIds = $this->linkedArticleIds($project);
        if ($articleIds === []) {
            return 0;
        }

        return $this->updateArticlesOwner($articleIds, $writerId, (int) ($project->site_id ?? 0));
    }

    public function assignWriterToArticle(SeoProject $project, int $articleId): bool
    {
        if ($articleId <= 0) {
            return false;
        }

        if (ContentProjectWriterAssignment::isUnassigned($project)) {
            return false;
        }

        $writerId = (int) ($project->user_id ?? 0);
        if ($writerId <= 0) {
            return false;
        }

        return $this->updateArticlesOwner([$articleId], $writerId, (int) ($project->site_id ?? 0)) > 0;
    }

    /**
     * @return list<int>
     */
    public function linkedArticleIds(SeoProject $project): array
    {
        return self::normalizeArticleIds(
            $project->tasks()
                ->whereNotNull('article_id')
                ->pluck('article_id'),
        );
    }

    /**
     * @param  iterable<int|string|null>  $ids
     * @return list<int>
     */
    public static function normalizeArticleIds(iterable $ids): array
    {
        $normalized = [];

        foreach ($ids as $id) {
            $value = (int) $id;
            if ($value > 0) {
                $normalized[$value] = $value;
            }
        }

        return array_values($normalized);
    }

    /**
     * @param  list<int>  $articleIds
     */
    private function updateArticlesOwner(array $articleIds, int $writerId, int $siteId): int
    {
        $query = SeoArticle::query()->whereIn('id', $articleIds);

        if ($siteId > 0) {
            $query->where('site_id', $siteId);
        }

        return $query
            ->where(function ($builder) use ($writerId): void {
                $builder
                    ->where('user_id', '!=', $writerId)
                    ->orWhereNull('user_id');
            })
            ->update(['user_id' => $writerId]);
    }
}
