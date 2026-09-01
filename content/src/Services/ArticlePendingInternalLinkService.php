<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;


use Omnichannel\Addons\SearchFoundation\Services\KeywordPersistenceService;
use Omnichannel\Addons\SearchFoundation\Services\KeywordMetaRepository;
use Omnichannel\Addons\SearchFoundation\Services\ArticleKeywordLinkReconcileService;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Services\KeywordProjectAssignmentService;
use Omnichannel\Addons\SearchFoundation\Models\SeoPendingInternalLink;
use Omnichannel\Addons\Seo\Services\SeoIssueProjectTaskAssignmentService;
use Omnichannel\Addons\WordPress\Support\WordPressPermalinkBuilder;
use Omnichannel\Addons\WordPress\Services\ArticleWordPressSyncFlagService;
use Omnichannel\Addons\WordPress\Services\WordPressArticleContentService;
use App\Models\Site;

final class ArticlePendingInternalLinkService
{
    public function __construct(
        private readonly KeywordPersistenceService $keywordPersistence,
        private readonly KeywordMetaRepository $keywordMetaRepository,
        private readonly WordPressArticleContentService $wordPressContent,
        private readonly WordPressPermalinkBuilder $permalinkBuilder,
        private readonly ArticleKeywordLinkReconcileService $articleReconcile,
        private readonly KeywordProjectAssignmentService $keywordProjectAssignment,
        private readonly SeoIssueProjectTaskAssignmentService $seoIssueAssignment,
    ) {}

    /**
     * @return array{
     *     success: bool,
     *     placeholder_href?: string,
     *     keyword_id?: int,
     *     assigned_to_project?: bool,
     *     already_has_target?: bool,
     *     message?: string,
     * }
     */
    public function assignFromEditor(SeoArticle $sourceArticle, string $anchorPhrase, ?int $projectId = null): array
    {
        $anchorPhrase = Keyword::decodePhrase(trim($anchorPhrase));
        if ($anchorPhrase === '') {
            return [
                'success' => false,
                'message' => __('seo-content-ai::filament.article_edit.pending_link_empty_phrase'),
            ];
        }

        $siteId = (int) ($sourceArticle->site_id ?? 0);
        if ($siteId <= 0) {
            return [
                'success' => false,
                'message' => __('seo-content-ai::filament.article_edit.pending_link_missing_site'),
            ];
        }

        $keyword = $this->keywordPersistence->upsert(
            $anchorPhrase,
            Keyword::TYPE_NORMAL,
            $siteId,
        );

        if ($keyword === null) {
            return [
                'success' => false,
                'message' => __('seo-content-ai::filament.article_edit.pending_link_keyword_failed'),
            ];
        }

        $keywordId = (int) $keyword->id;
        $mainArticleId = $this->keywordMetaRepository->getMainArticleIdForSite($keywordId, $siteId);
        if ($mainArticleId !== null && $mainArticleId > 0) {
            $targetUrl = $this->resolveArticleUrl(SeoArticle::query()->find($mainArticleId));
            if ($targetUrl !== '') {
                return [
                    'success' => true,
                    'placeholder_href' => $targetUrl,
                    'keyword_id' => $keywordId,
                    'assigned_to_project' => false,
                    'already_has_target' => true,
                    'message' => __('seo-content-ai::filament.article_edit.pending_link_existing_target'),
                ];
            }
        }

        $assignedToProject = false;
        $message = __('seo-content-ai::filament.article_edit.pending_link_created');

        if ($projectId !== null && $projectId > 0) {
            $keyword->refresh();
            $keyword->loadCount('mainArticles');

            $existingProjectForSite = $this->keywordProjectAssignment->keywordAssignedContentProjectIdForSite($keyword, $siteId);
            if ($existingProjectForSite === $projectId) {
                $assignedToProject = true;
                $message = __('seo-content-ai::filament.article_edit.pending_link_assigned');
            } elseif ($existingProjectForSite !== null) {
                return [
                    'success' => false,
                    'message' => $this->seoIssueAssignment->buildSummaryMessage([
                        'added' => 0,
                        'duplicate' => 0,
                        'overflow' => 0,
                        'domain_mismatch' => 0,
                        'already_in_project' => 1,
                    ]),
                ];
            } elseif (! $this->keywordProjectAssignment->canAssignKeyword($keyword)) {
                return [
                    'success' => false,
                    'message' => __('seo-content-ai::filament.keyword.workspace_assign_denied'),
                ];
            } else {
                $summary = $this->keywordProjectAssignment->assignKeywords(
                    collect([$keyword]),
                    $projectId,
                    $siteId,
                );
                $added = (int) ($summary['added'] ?? 0);
                $duplicate = (int) ($summary['duplicate'] ?? 0);

                if ($added > 0 || $duplicate > 0) {
                    $assignedToProject = true;
                    $message = __('seo-content-ai::filament.article_edit.pending_link_assigned');
                } else {
                    return [
                        'success' => false,
                        'message' => $this->seoIssueAssignment->buildSummaryMessage($summary),
                    ];
                }
            }
        } else {
            $message = __('seo-content-ai::filament.article_edit.pending_link_no_content_project');
        }

        try {
            $pending = $this->findReusablePending($sourceArticle, $keywordId, $anchorPhrase)
                ?? $this->createPending($sourceArticle, $keywordId, $anchorPhrase, $siteId);
        } catch (\Throwable $exception) {
            report($exception);

            return [
                'success' => false,
                'message' => __('seo-content-ai::filament.article_edit.pending_link_storage_failed'),
            ];
        }

        return [
            'success' => true,
            'placeholder_href' => $pending->placeholderHref(),
            'keyword_id' => $keywordId,
            'assigned_to_project' => $assignedToProject,
            'already_has_target' => false,
            'message' => $message,
        ];
    }

    public function resolveForKeyword(int $keywordId): int
    {
        if ($keywordId <= 0) {
            return 0;
        }

        $pendingLinks = SeoPendingInternalLink::query()
            ->where('keyword_id', $keywordId)
            ->where('status', SeoPendingInternalLink::STATUS_PENDING)
            ->with(['sourceArticle:id,site_id'])
            ->get();

        if ($pendingLinks->isEmpty()) {
            return 0;
        }

        $resolved = 0;
        $targetCache = [];

        foreach ($pendingLinks as $pending) {
            $siteId = (int) ($pending->sourceArticle?->site_id ?? 0);
            $cacheKey = $siteId > 0 ? 'site:'.$siteId : 'legacy';
            if (! array_key_exists($cacheKey, $targetCache)) {
                $mainArticleId = $siteId > 0
                    ? $this->keywordMetaRepository->getMainArticleIdForSite($keywordId, $siteId)
                    : $this->keywordMetaRepository->getMainArticleId($keywordId);
                if ($mainArticleId === null || $mainArticleId <= 0) {
                    $targetCache[$cacheKey] = null;
                } else {
                    $targetArticle = SeoArticle::query()->with(['site', 'articleMetas'])->find($mainArticleId);
                    if (
                        ! $targetArticle instanceof SeoArticle
                        || ($siteId > 0 && (int) ($targetArticle->site_id ?? 0) !== $siteId)
                    ) {
                        $targetCache[$cacheKey] = null;
                    } else {
                        $url = $this->resolveArticleUrl($targetArticle);
                        $targetCache[$cacheKey] = $url !== ''
                            ? ['id' => (int) $targetArticle->id, 'url' => $url]
                            : null;
                    }
                }
            }

            $target = $targetCache[$cacheKey];
            if (! is_array($target)) {
                continue;
            }

            if ($this->resolvePendingLink($pending, $target['url'], $target['id'])) {
                $resolved++;
            }
        }

        return $resolved;
    }

    public function resolveForMainArticle(SeoArticle $article): int
    {
        $articleId = (int) $article->id;
        if ($articleId <= 0) {
            return 0;
        }

        $keywordIds = \Omnichannel\Addons\SearchFoundation\Models\KeywordMeta::query()
            ->where('meta_key', \Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey::MainArticleId->value)
            ->where('meta_value', (string) $articleId)
            ->pluck('keyword_id');

        $resolved = 0;
        foreach ($keywordIds as $keywordId) {
            $resolved += $this->resolveForKeyword((int) $keywordId);
        }

        return $resolved;
    }

    public function replacePlaceholderInHtml(string $html, string $placeholderHash, string $targetUrl): string
    {
        $hash = trim($placeholderHash, '#');
        $targetUrl = trim($targetUrl);
        if ($hash === '' || $targetUrl === '') {
            return $html;
        }

        $variants = array_unique([$hash, strtoupper($hash), strtolower($hash)]);
        $next = $html;

        foreach ($variants as $variant) {
            $next = str_replace(
                [
                    'href="#'.$variant.'"',
                    "href='#".$variant."'",
                ],
                'href="'.str_replace('"', '&quot;', $targetUrl).'"',
                $next,
            );
        }

        return $next;
    }

    private function resolvePendingLink(SeoPendingInternalLink $pending, string $targetUrl, int $targetArticleId): bool
    {
        $sourceArticle = SeoArticle::query()->with('articleMetas')->find((int) $pending->source_article_id);
        if (! $sourceArticle instanceof SeoArticle) {
            return false;
        }

        $currentHtml = $this->articleReconcile->resolveArticleContent($sourceArticle);
        if ($currentHtml === '') {
            return false;
        }

        $updatedHtml = $this->replacePlaceholderInHtml(
            $currentHtml,
            (string) $pending->placeholder_hash,
            $targetUrl,
        );

        if ($updatedHtml === $currentHtml) {
            return false;
        }

        DB::connection($sourceArticle->getConnectionName())->transaction(function () use (
            $sourceArticle,
            $updatedHtml,
            $pending,
            $targetUrl,
            $targetArticleId,
        ): void {
            $sourceArticle->update(['body' => $updatedHtml]);
            try {
                $writer = app(\Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentWriter::class);
                $writer->invalidateForLegacyBodyWrite($sourceArticle, 'pending_internal_link');
                if ($sourceArticle->isDirty('editor_document_status')) {
                    $sourceArticle->save();
                }
            } catch (\Throwable) {
                // best-effort
            }

            $pending->update([
                'status' => SeoPendingInternalLink::STATUS_RESOLVED,
                'resolved_target_url' => $targetUrl,
                'resolved_target_article_id' => $targetArticleId,
                'resolved_at' => now(),
            ]);

            app(ArticleWordPressSyncFlagService::class)->markLocalEditPending($sourceArticle->fresh());
        });

        $this->articleReconcile->reconcileForArticle($sourceArticle->fresh(), $updatedHtml);

        return true;
    }

    private function findReusablePending(
        SeoArticle $sourceArticle,
        int $keywordId,
        string $anchorPhrase,
    ): ?SeoPendingInternalLink {
        return SeoPendingInternalLink::query()
            ->where('source_article_id', (int) $sourceArticle->id)
            ->where('keyword_id', $keywordId)
            ->where('anchor_phrase', $anchorPhrase)
            ->where('status', SeoPendingInternalLink::STATUS_PENDING)
            ->first();
    }

    private function createPending(
        SeoArticle $sourceArticle,
        int $keywordId,
        string $anchorPhrase,
        int $siteId,
    ): SeoPendingInternalLink {
        return SeoPendingInternalLink::query()->create([
            'site_id' => $siteId,
            'source_article_id' => (int) $sourceArticle->id,
            'keyword_id' => $keywordId,
            'anchor_phrase' => $anchorPhrase,
            'placeholder_hash' => $this->generateUniqueHash(),
            'status' => SeoPendingInternalLink::STATUS_PENDING,
        ]);
    }

    private function generateUniqueHash(): string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $hash = bin2hex(random_bytes(4));
            if (! SeoPendingInternalLink::query()->where('placeholder_hash', $hash)->exists()) {
                return $hash;
            }
        }

        throw new \RuntimeException('Unable to generate unique pending internal link hash.');
    }

    private function resolveArticleUrl(?SeoArticle $article): string
    {
        if (! $article instanceof SeoArticle) {
            return '';
        }

        $article->loadMissing('site', 'articleMetas');

        $cached = trim((string) ($article->articleMetas
            ->firstWhere('meta_key', 'wp_permalink')
            ?->meta_value ?? ''));
        $slug = trim((string) ($article->slug ?? ''));

        $resolved = trim($this->permalinkBuilder->resolve(
            $article,
            $cached,
            $slug !== '' ? $slug : null,
        ));
        if ($resolved !== '') {
            return $resolved;
        }

        $site = $article->site;
        if (! $site instanceof Site) {
            return '';
        }

        $base = rtrim($this->wordPressContent->getPermalinkBase($site), '/');
        if ($base === '' || $slug === '') {
            return '';
        }

        return $base.'/'.ltrim($slug, '/');
    }
}
