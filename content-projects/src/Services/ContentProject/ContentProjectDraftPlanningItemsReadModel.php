<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit\SeoAuditCheckIndexUrl;
use Illuminate\Support\Facades\Schema;

/**
 * Batch read-model for Draft planning items on the Content Planning page.
 */
final class ContentProjectDraftPlanningItemsReadModel
{
    /**
     * @param  array{
     *     review?: string,
     *     type?: string,
     * }  $filters
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     counts: array{all: int, unreviewed: int, reviewed: int},
     * }
     */
    public function forProject(SeoProject $project, array $filters = []): array
    {
        if (! $project->isDraftPlanning()) {
            return [
                'rows' => [],
                'counts' => ['all' => 0, 'unreviewed' => 0, 'reviewed' => 0],
            ];
        }

        $projectId = (int) $project->getKey();
        $hasPlanningReviewed = Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'planning_reviewed_at');

        $tasks = SeoProjectTask::query()
            ->where('project_id', $projectId)
            ->planned()
            ->inContentProjectWorkingSet()
            ->with([
                'itemOrigin',
                'article.seoProfile',
                'article.articleMetas' => static fn ($q) => $q->where('meta_key', 'wp_permalink'),
                'site:id,domain',
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        $rows = [];
        $unreviewed = 0;
        $reviewed = 0;
        foreach ($tasks as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }
            $row = $this->mapRow($task, $projectId, $hasPlanningReviewed);
            $rows[] = $row;
            if (! empty($row['planning_reviewed'])) {
                $reviewed++;
            } else {
                $unreviewed++;
            }
        }

        $counts = [
            'all' => count($rows),
            'unreviewed' => $unreviewed,
            'reviewed' => $reviewed,
        ];

        $reviewFilter = strtolower(trim((string) ($filters['review'] ?? 'all')));
        if ($reviewFilter === 'unreviewed') {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => empty($r['planning_reviewed'])));
        } elseif ($reviewFilter === 'reviewed') {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => ! empty($r['planning_reviewed'])));
        }

        $typeFilter = strtolower(trim((string) ($filters['type'] ?? 'all')));
        if (in_array($typeFilter, [
            SeoProjectTask::TYPE_REWRITE,
            SeoProjectTask::TYPE_IMPROVE,
            SeoProjectTask::TYPE_CREATE,
        ], true)) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $r): bool => (string) ($r['type'] ?? '') === $typeFilter,
            ));
        }

        return [
            'rows' => $rows,
            'counts' => $counts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRow(SeoProjectTask $task, int $projectId, bool $hasPlanningReviewed): array
    {
        $taskId = (int) $task->getKey();
        $type = SeoProjectTask::normalizeType($task->type);
        $article = $task->article;
        $articleId = (int) ($task->article_id ?? 0);
        $hasArticle = $articleId > 0 && $article instanceof SeoArticle;

        $origin = $task->relationLoaded('itemOrigin') ? $task->itemOrigin : null;
        $sourceType = $origin instanceof SeoContentProjectItemOrigin
            ? (string) ($origin->source_type ?: SeoContentProjectItemOrigin::SOURCE_MANUAL)
            : SeoContentProjectItemOrigin::SOURCE_MANUAL;

        $reasonCodes = $origin instanceof SeoContentProjectItemOrigin && is_array($origin->reason_codes)
            ? array_values(array_map('strval', $origin->reason_codes))
            : [];
        $issueCount = $sourceType === SeoContentProjectItemOrigin::SOURCE_SEO_AUDIT && $reasonCodes !== []
            ? count($reasonCodes)
            : null;

        $seoScore = null;
        if ($hasArticle) {
            $profileScore = $article->seoProfile?->seo_score;
            if ($profileScore !== null) {
                $seoScore = (int) round((float) $profileScore);
            } elseif ($article->seo_score !== null) {
                $seoScore = (int) round((float) $article->seo_score);
            }
        }

        $publicUrl = $this->resolveArticlePublicUrl($hasArticle ? $article : null);
        $editUrl = ($hasArticle && $article instanceof SeoArticle)
            ? ArticleResource::getUrl('edit', ['record' => $articleId])
            : null;

        $title = trim((string) ($task->title ?? ''));
        if ($title === '' && $hasArticle) {
            $title = trim((string) ($article->title ?? ''));
        }
        if ($title === '') {
            $title = '#'.$taskId;
        }

        $postTypeRaw = trim((string) ($task->post_type ?? ''));
        if ($postTypeRaw === '') {
            $postTypeRaw = SeoProjectTask::POST_TYPE_ARTICLE;
        }
        if ($postTypeRaw === 'post') {
            $postTypeRaw = SeoProjectTask::POST_TYPE_ARTICLE;
        }
        $isProductPostType = $postTypeRaw === SeoProjectTask::POST_TYPE_PRODUCT;

        // Global planning brief: secondary_description (Create/AI).
        // Product gallery / product-specific brief: task.description — only when post_type=product.
        $secondaryDescription = trim((string) ($task->secondary_description ?? ''));
        $taskDescription = trim((string) ($task->description ?? ''));
        $description = $secondaryDescription;
        if ($description === '' && ! $isProductPostType) {
            // Legacy Post/Rewrite fallback — never steal Product gallery into global line.
            $description = $taskDescription;
        }
        if ($description === '' && in_array($type, [SeoProjectTask::TYPE_REWRITE, SeoProjectTask::TYPE_IMPROVE], true)) {
            $description = trim((string) ($task->rewrite_notes ?? ''));
        }
        $productDescription = ($isProductPostType && $taskDescription !== '') ? $taskDescription : null;

        $keyword = trim((string) ($task->keyword ?? $task->source_content ?? ''));
        $plannerRunId = ($origin instanceof SeoContentProjectItemOrigin && (int) ($origin->planner_run_id ?? 0) > 0)
            ? (int) $origin->planner_run_id
            : null;

        $isAiCreate = $sourceType === SeoContentProjectItemOrigin::SOURCE_AI_NEW_CONTENT
            && SeoProjectTask::isNewArticleType($type);

        $planningReviewed = false;
        if ($hasPlanningReviewed) {
            $planningReviewed = $task->planning_reviewed_at !== null;
        }

        $iconKind = match (true) {
            $isAiCreate => 'create',
            in_array($type, [SeoProjectTask::TYPE_REWRITE, SeoProjectTask::TYPE_IMPROVE], true) => 'improve',
            default => 'manual',
        };

        $status = strtolower(trim((string) ($task->status ?? '')));
        $canEditPostType = SeoProjectTask::isNewArticleType($type)
            && ! $hasArticle
            && $task->archived_at === null
            && in_array($status, [SeoProjectTask::STATUS_PENDING, SeoProjectTask::STATUS_DRAFT], true);
        $addedAt = null;
        if ($origin instanceof SeoContentProjectItemOrigin && $origin->created_at !== null) {
            $addedAt = $origin->created_at;
        } elseif ($task->created_at !== null) {
            $addedAt = $task->created_at;
        }

        return [
            'id' => $taskId,
            'title' => $title,
            'domain' => $this->resolveItemDomain($task),
            'site_id' => (int) ($task->site_id ?? 0) ?: null,
            'description' => $description !== '' ? $description : null,
            'product_description' => $productDescription,
            'keyword' => $keyword !== '' ? $keyword : null,
            'icon_kind' => $iconKind,
            'article_id' => $hasArticle ? $articleId : null,
            'article_public_url' => $publicUrl,
            'article_edit_url' => $editUrl,
            'title_href' => $publicUrl ?? $editUrl,
            'title_external' => $publicUrl !== null,
            'seo_score' => $seoScore,
            'seo_score_label' => $seoScore !== null ? (string) $seoScore : '—',
            'issue_count' => $issueCount,
            'issue_count_label' => $issueCount !== null ? (string) $issueCount : '—',
            'issue_tooltip' => $issueCount !== null && $issueCount > 0
                ? (string) $issueCount.' SEO issues'
                : null,
            'type' => $type,
            'plan_label' => match ($type) {
                SeoProjectTask::TYPE_REWRITE => 'Rewrite',
                SeoProjectTask::TYPE_IMPROVE => 'Improve',
                SeoProjectTask::TYPE_CREATE => 'Create',
                default => ucfirst($type !== '' ? $type : 'Item'),
            },
            'post_type' => $postTypeRaw,
            'post_type_label' => $this->postTypeLabel($postTypeRaw),
            'can_edit_post_type' => $canEditPostType,
            'source_type' => $sourceType,
            'source_label' => match ($sourceType) {
                SeoContentProjectItemOrigin::SOURCE_SEO_AUDIT => 'SEO Audit',
                SeoContentProjectItemOrigin::SOURCE_AI_NEW_CONTENT => 'AI Suggestion',
                default => 'Manual',
            },
            'check_index_url' => SeoAuditCheckIndexUrl::forCanonicalUrl($publicUrl),
            'can_check_index' => $publicUrl !== null && ! $isAiCreate,
            'can_open_public' => $publicUrl !== null,
            'can_edit_article' => $editUrl !== null && ! $isAiCreate,
            'can_skip_seo_audit' => $hasArticle
                && $sourceType === SeoContentProjectItemOrigin::SOURCE_SEO_AUDIT,
            'planner_run_id' => $plannerRunId,
            'planning_reviewed' => $planningReviewed,
            'planning_reviewed_at' => $planningReviewed
                ? $task->planning_reviewed_at?->toIso8601String()
                : null,
            'added_at' => $addedAt?->toIso8601String(),
            'added_label' => $addedAt?->diffForHumans() ?? '—',
            'updated_label' => $task->updated_at?->diffForHumans() ?? '—',
        ];
    }

    private function postTypeLabel(string $postType): string
    {
        $key = strtolower(trim($postType));

        return match ($key) {
            SeoProjectTask::POST_TYPE_ARTICLE, 'post' => (string) __('seo-content-ai::filament.article_list.post_type_post'),
            SeoProjectTask::POST_TYPE_PRODUCT => (string) __('seo-content-ai::filament.article_list.post_type_product'),
            'page' => (string) __('seo-content-ai::filament.article_list.post_type_page'),
            SeoProjectTask::POST_TYPE_CATEGORY => (string) __('seo-content-ai::filament.article_list.post_type_category'),
            SeoProjectTask::POST_TYPE_PRODUCT_CATEGORY => (string) __('seo-content-ai::filament.article_list.post_type_product_category'),
            default => $key !== '' ? ucfirst($key) : (string) __('seo-content-ai::filament.article_list.post_type_post'),
        };
    }

    private function resolveItemDomain(SeoProjectTask $task): string
    {
        $site = $task->relationLoaded('site') ? $task->site : null;
        if ($site instanceof \App\Models\Site) {
            $domain = trim((string) ($site->domain ?? ''));

            return $domain !== '' ? $domain : '—';
        }

        $siteId = (int) ($task->site_id ?? 0);
        if ($siteId <= 0) {
            return '—';
        }

        $domain = trim((string) (\App\Models\Site::query()->whereKey($siteId)->value('domain') ?? ''));

        return $domain !== '' ? $domain : '—';
    }

    private function resolveArticlePublicUrl(?SeoArticle $article): ?string
    {
        if (! $article instanceof SeoArticle) {
            return null;
        }

        if ($article->relationLoaded('articleMetas')) {
            $permalink = trim((string) ($article->articleMetas->firstWhere('meta_key', 'wp_permalink')?->meta_value ?? ''));
            if ($permalink !== '') {
                return $permalink;
            }
        }

        return null;
    }
}
