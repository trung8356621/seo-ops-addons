<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArticleMembership;
use Omnichannel\Addons\Publishing\Services\Publishing\PostPublishWordPressSyncEligibility;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Support\RuntimeLogger;

final class ArticleWordPressSyncEligibility
{
    public const MODE_LEGACY_MANUAL = 'legacy_manual';

    public const MODE_POST_PUBLISH_UPDATE = 'post_publish_update';

    public const MODE_REWRITE_UPDATE_EXISTING = 'update_existing';

    public function __construct(
        private readonly ContentProjectArticleMembership $membership,
        private readonly PostPublishWordPressSyncEligibility $postPublishEligibility,
    ) {}

    /**
     * @return array{
     *     allowed: bool,
     *     reason: ?string,
     *     message: ?string,
     *     mode: string|null,
     *     remote_post_id: int|null,
     *     task: ?SeoProjectTask,
     *     item_type: string|null,
     *     post_publish_eligible?: bool
     * }
     */
    public function evaluate(SeoArticle $article): array
    {
        if (SeoAccessControl::isContentManager() || ! SeoAccessControl::canSyncArticlesToWordPress()) {
            return $this->deny('permission_denied', 'Bạn không có quyền đồng bộ bài viết lên WordPress.');
        }

        if (! SeoAccessControl::canAccessArticle($article)) {
            return $this->deny('tenant_denied', 'Bài không thuộc tenant/site hiện tại.');
        }

        $task = $this->membership->assignedTaskForArticle($article);
        if (! $task instanceof SeoProjectTask) {
            return [
                'allowed' => true,
                'reason' => null,
                'message' => null,
                'mode' => self::MODE_LEGACY_MANUAL,
                'remote_post_id' => (int) ($article->wordpressLink?->wp_post_id ?? 0) ?: null,
                'task' => null,
                'item_type' => null,
            ];
        }

        $itemType = SeoProjectTask::normalizeType($task->type ?? null);
        $remotePostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);

        if (in_array($itemType, [SeoProjectTask::TYPE_REWRITE, SeoProjectTask::TYPE_IMPROVE], true)) {
            if ($remotePostId <= 0) {
                RuntimeLogger::warning('article_wp_sync.rewrite_missing_remote_post', [
                    'article_id' => (int) $article->id,
                    'project_item_id' => (int) $task->id,
                    'connection_id' => (int) ($article->site_id ?? $task->site_id ?? 0) ?: null,
                    'expected_remote_reference' => 'articles.wp_post_id',
                ]);

                return $this->deny(
                    'missing_remote_post',
                    'Không tìm thấy bài WordPress gốc để đồng bộ.',
                    $task,
                    $itemType,
                );
            }

            return [
                'allowed' => true,
                'reason' => null,
                'message' => null,
                'mode' => self::MODE_REWRITE_UPDATE_EXISTING,
                'remote_post_id' => $remotePostId,
                'task' => $task,
                'item_type' => $itemType,
                'post_publish_eligible' => false,
            ];
        }

        $postPublish = $this->postPublishEligibility->evaluate($article);
        if (($postPublish['allowed'] ?? false) === true) {
            return [
                'allowed' => true,
                'reason' => null,
                'message' => null,
                'mode' => self::MODE_POST_PUBLISH_UPDATE,
                'remote_post_id' => (int) ($postPublish['wp_post_id'] ?? $remotePostId) ?: null,
                'task' => $task,
                'item_type' => $itemType,
                'post_publish_eligible' => true,
            ];
        }

        $queue = ContentProjectPublishQueueStatus::tryFrom((string) ($task->publish_queue_status ?? ''))
            ?? ContentProjectPublishQueueStatus::None;

        return $this->deny(
            (string) ($postPublish['code'] ?? 'not_published'),
            $queue === ContentProjectPublishQueueStatus::Published
                ? (string) ($postPublish['message'] ?? 'Không đủ điều kiện đồng bộ WordPress.')
                : 'Chỉ đồng bộ WordPress sau khi Publishing Queue xác nhận Published.',
            $task,
            $itemType,
        );
    }

    public function isAllowed(SeoArticle $article): bool
    {
        return (bool) ($this->evaluate($article)['allowed'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    private function deny(
        string $reason,
        string $message,
        ?SeoProjectTask $task = null,
        ?string $itemType = null,
    ): array {
        return [
            'allowed' => false,
            'reason' => $reason,
            'message' => $message,
            'mode' => null,
            'remote_post_id' => null,
            'task' => $task,
            'item_type' => $itemType,
        ];
    }
}
