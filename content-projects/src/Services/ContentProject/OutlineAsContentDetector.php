<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Services\Workflow\ArtifactReusePolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnostic only — report articles whose body looks like outline-as-content.
 * Never mutates rows.
 */
final class OutlineAsContentDetector
{
    public function __construct(
        private readonly ArtifactReusePolicy $reusePolicy,
    ) {}

    /**
     * @return list<array{article_id: int, project_task_id: int|null, reason: string, excerpt: string}>
     */
    public function detect(?int $articleId = null, int $limit = 200): array
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('articles')) {
            return [];
        }

        $query = SeoArticle::query()
            ->select(['id', 'body'])
            ->orderByDesc('id')
            ->limit(max(1, min($limit, 2000)));

        if ($articleId !== null && $articleId > 0) {
            $query->where('id', $articleId);
        }

        /** @var Collection<int, SeoArticle> $articles */
        $articles = $query->get();
        $hits = [];

        foreach ($articles as $article) {
            $body = trim((string) ($article->body ?? ''));
            if ($body === '') {
                continue;
            }

            $reason = $this->classify($body);
            if ($reason === null) {
                continue;
            }

            $hits[] = [
                'article_id' => (int) $article->getKey(),
                'project_task_id' => null,
                'reason' => $reason,
                'excerpt' => mb_substr(strip_tags($body), 0, 160),
            ];
        }

        return $hits;
    }

    public function classify(string $body): ?string
    {
        $plain = trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($plain === '') {
            return null;
        }

        if ($this->reusePolicy->looksLikeOutlineMarkerPayload($plain)
            || $this->reusePolicy->looksLikeOutlineMarkerPayload($body)
        ) {
            return 'body_starts_with_or_contains_outline_marker';
        }

        if (preg_match('/^\s*\[START_TASK_\d+_OUTLINE\]/i', $plain) === 1) {
            return 'body_starts_with_outline_marker';
        }

        return null;
    }
}
