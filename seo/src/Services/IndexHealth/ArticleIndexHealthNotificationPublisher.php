<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\IndexHealth;

use App\Models\Site;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Enums\NotificationSeverity;
use Omnichannel\Addons\Seo\Enums\OperationalNotificationEventCode;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationDeepLinks;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationRecipientResolver;
use Omnichannel\Addons\Seo\Services\Notifications\OperationalNotificationService;

final class ArticleIndexHealthNotificationPublisher
{
    public function notifyDropped(SeoArticle $article, string $url, ?int $initiatorUserId = null): void
    {
        $articleId = (int) $article->getKey();
        $siteId = (int) ($article->site_id ?? 0);
        if ($articleId <= 0) {
            return;
        }

        $tenantOwnerId = $this->tenantOwnerIdForSite($siteId);
        if ($tenantOwnerId <= 0) {
            return;
        }

        $dedup = $this->droppedDedupKey($articleId);
        $title = trim((string) ($article->title ?? '')) ?: ('Article #'.$articleId);
        $links = app(OperationalNotificationDeepLinks::class);
        $indexUrl = $links->articleIndexHealth($articleId, $siteId);

        app(OperationalNotificationService::class)->notify(
            eventCode: OperationalNotificationEventCode::ArticleIndexDropped,
            severity: NotificationSeverity::Warning,
            recipients: app(OperationalNotificationRecipientResolver::class)
                ->forPublishing($tenantOwnerId, $initiatorUserId),
            title: 'Article dropped from index',
            message: sprintf('%s is no longer indexed.', $title),
            context: [
                'tenant_id' => $tenantOwnerId,
                'connection_id' => $siteId,
                'article_id' => $articleId,
                'url' => $url,
                'source' => 'article_index_health',
            ],
            actionUrl: $indexUrl,
            actions: [
                ['label' => 'Open Index Health', 'url' => $indexUrl, 'name' => 'open_index_health'],
            ],
            dedupKey: $dedup,
            groupKey: $dedup,
            resolvable: true,
        );
    }

    public function resolveDropped(SeoArticle $article): void
    {
        $articleId = (int) $article->getKey();
        if ($articleId <= 0) {
            return;
        }

        app(OperationalNotificationService::class)->resolve($this->droppedDedupKey($articleId));
    }

    public function droppedDedupKey(int $articleId): string
    {
        return sprintf('article-index-health:%d:dropped', $articleId);
    }

    private function tenantOwnerIdForSite(int $siteId): int
    {
        if ($siteId <= 0) {
            return 0;
        }

        $site = Site::query()->find($siteId);
        if ($site === null) {
            return 0;
        }

        return (int) ($site->user_id ?? $site->owner_id ?? 0);
    }
}
