<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectGenerationKeyword;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemIdentity;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use Omnichannel\Addons\ContentProjects\Support\SeoQueueContext;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordFocusAttach;
use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\WordPress\Services\SideEffect\ManualWordPressContext;
use Omnichannel\Addons\WordPress\Services\SyncDomainContentService;
use Omnichannel\Addons\WordPress\Services\WordPressArticleSyncService;
use Illuminate\Support\Str;

/**
 * For rewrite items: Content Project keyword is authority.
 * Push CP keyword → WordPress SEO meta → sync Article back → stamp prompt variables.
 * Never overwrite planning keyword from WP/Article after generate.
 */
final class ContentProjectRewriteKeywordCanonicalizer
{
    public const ERROR_MESSAGE = 'Không thể đồng bộ từ khóa SEO sang WordPress. Chưa bắt đầu rewrite.';

    public function __construct(
        private readonly WordPressArticleSyncService $wpSync,
        private readonly SyncDomainContentService $inboundSync,
        private readonly SeoAnalyzerService $seoAnalyzer,
    ) {}

    /**
     * @throws \RuntimeException when CP keyword is explicit but WP/local sync fails
     */
    public function canonicalize(SeoProjectTask $task, TaskTestContext $context): TaskTestContext
    {
        $type = SeoProjectTask::normalizeType((string) ($task->type ?? $context->projectTaskType ?? ''));
        if ($type !== SeoProjectTask::TYPE_REWRITE) {
            return $context;
        }

        $article = $context->article;
        if (! $article instanceof SeoArticle) {
            return $context;
        }

        $projectKeyword = ContentProjectGenerationKeyword::effective($task);
        $article->loadMissing(['articleMetas', 'wordpressLink', 'site']);

        $currentKeyword = ContentProjectItemIdentity::normalize(
            $this->seoAnalyzer->resolveFocusKeywordForArticle($article),
        );
        $title = ContentProjectItemIdentity::normalize(
            $article->title !== null ? (string) $article->title : null,
        );

        if ($projectKeyword !== '') {
            $storedKeyword = Keyword::preparePhraseForStorage($projectKeyword);
            $this->forceProjectKeywordOntoWordPressAndArticle($article, $storedKeyword);
            $article = $article->fresh(['articleMetas', 'wordpressLink', 'site']) ?? $article;
            $verified = ContentProjectItemIdentity::normalize(
                $this->seoAnalyzer->resolveFocusKeywordForArticle($article),
            );
            if (mb_strtolower($verified) !== mb_strtolower($storedKeyword)) {
                throw new \RuntimeException(self::ERROR_MESSAGE);
            }
            $target = $storedKeyword;
        } elseif ($currentKeyword !== '') {
            $target = $currentKeyword;
        } else {
            $target = $title;
        }

        $variables = $context->variables;
        if ($target !== '') {
            $variables['focus_keyword'] = $target;
            $variables['keyword'] = $target;
        }

        return $context
            ->withArticle($article, $context->isNewArticle, $context->matchedBy)
            ->withVariables($variables);
    }

    private function forceProjectKeywordOntoWordPressAndArticle(SeoArticle $article, string $keyword): void
    {
        $siteId = (int) ($article->site_id ?? 0);
        $articleId = (int) $article->getKey();
        if ($siteId <= 0 || $articleId <= 0) {
            throw new \RuntimeException(self::ERROR_MESSAGE);
        }

        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            throw new \RuntimeException(self::ERROR_MESSAGE);
        }

        $userId = auth()->id() !== null ? (int) auth()->id() : 0;
        if ($userId <= 0) {
            $userId = (int) ($article->user_id ?? 0);
        }
        if ($userId <= 0) {
            $userId = 1;
        }

        KeywordFocusAttach::syncMainKeyword(
            $article,
            $siteId,
            $userId,
            $keyword,
        );

        $requestId = (string) Str::uuid();
        $sideEffect = new ManualWordPressContext(
            userId: $userId,
            requestId: $requestId,
            articleId: $articleId,
            siteId: $siteId,
            reason: 'content_project.rewrite.canonicalize_focus_keyword',
            correlationId: $requestId,
        );

        // Queue/system actors have no Auth → SeoAccessControl defaults to content_manager.
        // Canonicalize is an infrastructure push (CP keyword authority), same as other queue WP syncs.
        $push = SeoQueueContext::runWpSyncFromQueue(
            fn (): array => $this->wpSync->syncSeoMetaForArticle($article, $sideEffect, [
                'focus_keyword' => $keyword,
            ]),
        );
        if (! ($push['success'] ?? false)) {
            throw new \RuntimeException(self::ERROR_MESSAGE);
        }

        $pull = SeoQueueContext::runWpSyncFromQueue(
            fn (): array => $this->inboundSync->syncSingleArticleFromWordPress($article->fresh() ?? $article),
        );
        if (! ($pull['success'] ?? false)) {
            throw new \RuntimeException(self::ERROR_MESSAGE);
        }
    }
}
