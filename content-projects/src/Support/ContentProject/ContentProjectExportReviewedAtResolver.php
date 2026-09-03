<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Illuminate\Support\Carbon;

/**
 * Excel "Reviewed at" for Content Project exports.
 *
 * Priority:
 *   reviewed_at ?? last_update_wp ?? wp_created_at
 *
 * Live article mapping when dedicated columns are absent:
 * - reviewed_at → articles.reviewed_at
 * - last_update_wp → wordpress_article_links.observed_modified_at
 *                    ?? external_modified_at
 * - wp_created_at → snapshot/attribute only (no reliable WP post_date column yet)
 */
final class ContentProjectExportReviewedAtResolver
{
    /**
     * @param  array<string, mixed>  $bag  Snapshot or export row fields
     */
    public function resolve(array $bag = [], ?SeoArticle $article = null): mixed
    {
        return $this->firstNonEmpty([
            $bag['reviewed_at'] ?? null,
            $article?->reviewed_at,
            $bag['last_update_wp'] ?? null,
            $this->lastUpdateWpFromArticle($article),
            $bag['wp_created_at'] ?? null,
            $this->wpCreatedAtFromArticle($article),
        ]);
    }

    /**
     * Values to persist/project into archive snapshots for export reuse.
     *
     * @return array{reviewed_at: mixed, last_update_wp: mixed, wp_created_at: mixed}
     */
    public function exportFields(?SeoArticle $article): array
    {
        return [
            'reviewed_at' => $article?->reviewed_at,
            'last_update_wp' => $this->lastUpdateWpFromArticle($article),
            'wp_created_at' => $this->wpCreatedAtFromArticle($article),
        ];
    }

    private function lastUpdateWpFromArticle(?SeoArticle $article): mixed
    {
        if (! $article instanceof SeoArticle) {
            return null;
        }

        $explicit = $article->getAttributes()['last_update_wp'] ?? null;
        if ($this->isPresent($explicit)) {
            return $explicit;
        }

        $article->loadMissing('wordpressLink');
        $link = $article->wordpressLink;

        return $link?->observed_modified_at ?? $link?->external_modified_at;
    }

    private function wpCreatedAtFromArticle(?SeoArticle $article): mixed
    {
        if (! $article instanceof SeoArticle) {
            return null;
        }

        $explicit = $article->getAttributes()['wp_created_at'] ?? null;
        if ($this->isPresent($explicit)) {
            return $explicit;
        }

        return null;
    }

    /**
     * @param  list<mixed>  $values
     */
    private function firstNonEmpty(array $values): mixed
    {
        foreach ($values as $value) {
            if (! $this->isPresent($value)) {
                continue;
            }

            return $value;
        }

        return null;
    }

    private function isPresent(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if ($value instanceof Carbon) {
            return true;
        }

        if (is_string($value) && trim($value) === '') {
            return false;
        }

        return true;
    }
}
