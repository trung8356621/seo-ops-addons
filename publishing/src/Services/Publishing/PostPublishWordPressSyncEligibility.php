<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Services\Publishing;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArticleMembership;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

/**
 * Gate for post-publish editorial WordPress UPDATE sync.
 * Does NOT use articles.status alone — requires Publishing Queue lifecycle evidence.
 */
final class PostPublishWordPressSyncEligibility
{
    public const CODE_OK = 'ok';

    public const CODE_NOT_CONTENT_PROJECT = 'not_content_project';

    public const CODE_NOT_PUBLISHED = 'not_published';

    public const CODE_MISSING_PUBLISHED_AT = 'missing_published_at';

    public const CODE_TENANT_DENIED = 'tenant_denied';

    public const CODE_PUBLISHER_ACTIVE = 'publisher_active';

    public const CODE_SYNC_LOCKED = 'sync_locked';

    public function __construct(
        private readonly ContentProjectArticleMembership $membership,
        private readonly PublishingActiveProcessing $activeProcessing,
    ) {}

    /**
     * @return array{
     *     allowed: bool,
     *     code: string,
     *     message: string,
     *     task: ?SeoProjectTask,
     *     wp_post_id: int,
     *     needs_reconcile: bool
     * }
     */
    public function evaluate(SeoArticle $article): array
    {
        if (! SeoAccessControl::canAccessArticle($article)) {
            return $this->deny(self::CODE_TENANT_DENIED, 'Bài không thuộc tenant/site hiện tại.');
        }

        $task = $this->membership->assignedTaskForArticle($article);
        if (! $task instanceof SeoProjectTask) {
            return $this->deny(self::CODE_NOT_CONTENT_PROJECT, 'Bài không thuộc Content Project / Publishing Queue.');
        }

        $queue = ContentProjectPublishQueueStatus::tryFrom((string) ($task->publish_queue_status ?? ''))
            ?? ContentProjectPublishQueueStatus::None;

        if ($queue !== ContentProjectPublishQueueStatus::Published) {
            return $this->deny(
                self::CODE_NOT_PUBLISHED,
                'Chỉ đồng bộ WordPress sau khi Publishing Queue xác nhận Published.',
            );
        }

        if ($task->publish_published_at === null) {
            return $this->deny(
                self::CODE_MISSING_PUBLISHED_AT,
                'Thiếu publish_published_at — chưa có bằng chứng xuất bản chuẩn.',
            );
        }

        if ($this->activeProcessing->isActivelyPublishing($task)) {
            return $this->deny(
                self::CODE_PUBLISHER_ACTIVE,
                'Đang có lượt xuất bản đang chạy cho bài này. Thử lại sau.',
            );
        }

        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);

        return [
            'allowed' => true,
            'code' => self::CODE_OK,
            'message' => 'eligible',
            'task' => $task,
            'wp_post_id' => $wpPostId,
            'needs_reconcile' => $wpPostId <= 0,
        ];
    }

    public function isEligible(SeoArticle $article): bool
    {
        return (bool) ($this->evaluate($article)['allowed'] ?? false);
    }

    /**
     * @return array{
     *     allowed: bool,
     *     code: string,
     *     message: string,
     *     task: ?SeoProjectTask,
     *     wp_post_id: int,
     *     needs_reconcile: bool
     * }
     */
    private function deny(string $code, string $message): array
    {
        return [
            'allowed' => false,
            'code' => $code,
            'message' => $message,
            'task' => null,
            'wp_post_id' => 0,
            'needs_reconcile' => false,
        ];
    }
}
