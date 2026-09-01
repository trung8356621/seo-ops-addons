<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\ContentProjectRunSettings;

/**
 * Post-type (article) automation steps after workflow content generation.
 */
final class ContentProjectPostRunPipeline
{
    public function __construct(
        private readonly ContentProjectPostImageService $postImages,
        private readonly SeoMediaArticleSlugFixAllService $slugFixAll,
    ) {}

    /**
     * @return array{message_suffix: string, image_stats: array<string, mixed>|null, slug_fix: array<string, mixed>|null}
     */
    public function apply(
        SeoProjectTask $task,
        SeoProjectRun $run,
        SeoArticle $article,
        ?SeoProjectRunItem $runItem = null,
    ): array {
        if (! $this->isPostTask($task)) {
            return ['message_suffix' => '', 'image_stats' => null, 'slug_fix' => null];
        }

        $settings = ContentProjectRunSettings::fromRun($run);
        $imageStats = null;
        $parts = [];

        if ($settings->generatePostImages) {
            $imageStats = $this->postImages->generateForRun(
                $article,
                $run,
                $runItem instanceof SeoProjectRunItem ? (int) $runItem->id : 0,
            );
            $article->refresh();

            $success = (int) ($imageStats['success'] ?? 0);
            $failed = (int) ($imageStats['failed'] ?? 0);
            $total = $success + $failed;

            if ($total === 0) {
                $parts[] = 'Không có section phù hợp để tạo ảnh.';
            } elseif ($failed === 0) {
                $parts[] = 'tạo '.$success.' ảnh';
            } else {
                $parts[] = 'tạo thành công '.$success.'/'.$total.' ảnh; '.$failed.' ảnh lỗi';
            }
        } else {
            $parts[] = 'Bỏ qua tạo ảnh theo Run settings';
        }

        $slugFix = null;
        if ($this->slugFixAll->articleHasLocalImages($article)) {
            $slugFix = $this->slugFixAll->fixAllForArticle($article);
            if (($slugFix['applied'] ?? 0) > 0) {
                $parts[] = 'chuẩn hóa slug ảnh';
            }
        }

        $suffix = $parts !== [] ? '. '.ucfirst(implode(', ', $parts)).'.' : '';

        return [
            'message_suffix' => $suffix,
            'image_stats' => $imageStats,
            'slug_fix' => $slugFix,
        ];
    }

    public function isPostTask(SeoProjectTask $task): bool
    {
        if (! SeoProjectTask::isNewArticleType($task->type)) {
            return false;
        }

        return SeoProjectTask::normalizePostType($task->post_type) === SeoProjectTask::POST_TYPE_POST;
    }
}
