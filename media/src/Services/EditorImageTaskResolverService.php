<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;

final class EditorImageTaskResolverService
{
    public function __construct(
        private readonly TaskWorkflowTestRunner $workflowRunner,
    ) {}

    public function resolveImagePrompt(?int $taskId): SeoPrompt
    {
        if ($taskId === null) {
            throw new \InvalidArgumentException(
                'Chưa cấu hình quy trình «Tạo ảnh». Vào SEO → Cài đặt → Quy trình.',
            );
        }

        $task = SeoTask::query()->where('is_active', true)->find($taskId);
        if ($task === null) {
            throw new \InvalidArgumentException(
                "Quy trình «Tạo ảnh» (#{$taskId}) không tồn tại hoặc đã tắt.",
            );
        }

        return $this->workflowRunner->resolveImagePromptForTask($task);
    }

    public function resolveVideoPrompt(?int $taskId): SeoPrompt
    {
        if ($taskId === null) {
            throw new \InvalidArgumentException(
                'Chưa cấu hình quy trình «Tạo video». Vào SEO → Cài đặt → Quy trình.',
            );
        }

        $task = SeoTask::query()->where('is_active', true)->find($taskId);
        if ($task === null) {
            throw new \InvalidArgumentException(
                "Quy trình «Tạo video» (#{$taskId}) không tồn tại hoặc đã tắt.",
            );
        }

        return $this->workflowRunner->resolveVideoPromptForTask($task);
    }
}
