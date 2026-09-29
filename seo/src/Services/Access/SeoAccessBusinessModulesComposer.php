<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Access;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentExecutionContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentReadService;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;

/** Compact, site-scoped read projections for Agent business modules. */
final class SeoAccessBusinessModulesComposer
{
    public function __construct(private readonly ContentProjectAgentReadService $projects) {}

    /** @return array<string, mixed> */
    public function articles(int $siteId, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $rows = SeoArticle::query()
            ->where('site_id', $siteId)
            ->where('status', '!=', 'trash')
            ->with('seoProfile:article_id,seo_score,focus_keyword,internal_link_count,external_link_count')
            ->orderBy('updated_at')
            ->limit($limit)
            ->get(['id', 'site_id', 'title', 'slug', 'status', 'created_at', 'updated_at'])
            ->map(static function (SeoArticle $article): array {
                $profile = $article->seoProfile;

                return [
                    'article_ref' => 'article:'.$article->id,
                    'title' => (string) $article->title,
                    'slug' => $article->slug,
                    'status' => (string) $article->status,
                    'focus_keyword' => $profile?->focus_keyword,
                    'seo_score' => $profile?->seo_score !== null ? (float) $profile->seo_score : null,
                    'internal_link_count' => $profile?->internal_link_count !== null ? (int) $profile->internal_link_count : null,
                    'external_link_count' => $profile?->external_link_count !== null ? (int) $profile->external_link_count : null,
                    'created_at' => $article->created_at?->toIso8601String(),
                    'updated_at' => $article->updated_at?->toIso8601String(),
                ];
            })->all();

        return ['schema' => 'seo.access.articles.v1', 'site_ref' => 'site:'.$siteId, 'articles' => $rows];
    }

    /** @return array<string, mixed> */
    public function links(int $siteId, bool $internal): array
    {
        $types = $internal
            ? [SeoLinkMapType::Internal->value]
            : [SeoLinkMapType::External->value, SeoLinkMapType::WikiTrust->value, SeoLinkMapType::ManagedCrossSite->value, SeoLinkMapType::NeedsReview->value];

        $rows = SeoLinkMap::query()
            ->whereHas('sourceArticle', static fn ($query) => $query->where('site_id', $siteId))
            ->whereIn('link_type', $types)
            ->with(['sourceArticle:id,title,slug', 'targetArticle:id,title,slug'])
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(static fn (SeoLinkMap $link): array => [
                'source_article_ref' => 'article:'.$link->source_article_id,
                'source_title' => $link->sourceArticle?->title,
                'target_article_ref' => $link->target_article_id ? 'article:'.$link->target_article_id : null,
                'target_title' => $link->targetArticle?->title,
                'destination' => $link->target_external_url,
                'anchor_text' => $link->anchor_text,
                'link_type' => $link->link_type instanceof SeoLinkMapType ? $link->link_type->value : (string) $link->link_type,
                'status' => $link->status?->value ?? (is_string($link->status) ? $link->status : null),
                'http_status' => $link->last_http_status,
            ])->all();

        return [
            'schema' => $internal ? 'seo.access.internal-links.v1' : 'seo.access.external-links.v1',
            'site_ref' => 'site:'.$siteId,
            'links' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    public function contentProjects(int $siteId, ?string $period, string $requestRef): array
    {
        $context = new AgentExecutionContext(
            actorRef: 'seo-access', actorType: 'agent', tenantRef: 'seo-access',
            siteRef: 'site:'.$siteId, requestRef: $requestRef, resolvedSiteId: $siteId,
            scopes: ['content-projects:read'],
        );
        $projects = $this->projects->listProjects($context)['projects'] ?? [];
        if (is_string($period) && $period !== '') {
            $projects = array_values(array_filter($projects, static fn (array $project): bool => str_starts_with((string) ($project['month'] ?? ''), $period)));
        }
        foreach ($projects as $index => $project) {
            $projectRef = (string) ($project['project_ref'] ?? '');
            if ($projectRef !== '') {
                $projects[$index]['items'] = $this->projects->listItems($context, ['project_ref' => $projectRef])['items'] ?? [];
            }
        }

        return ['schema' => 'seo.access.content-projects.v1', 'site_ref' => 'site:'.$siteId, 'projects' => $projects];
    }
}
