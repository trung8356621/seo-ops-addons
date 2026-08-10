<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\WorkflowRoles;

use Omnichannel\Addons\Content\Contracts\SeoCreateArticleSettingsReader;
use Omnichannel\Addons\ContentProjects\Enums\WorkflowCapability;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;

/**
 * Validate Settings gắn Workflow đủ role bắt buộc trước khi lưu.
 */
final class WorkflowAssignmentValidator
{
    public function __construct(
        private readonly WorkflowExecutionRoleResolver $roleResolver,
        private readonly SeoCreateArticleSettingsReader $settings,
    ) {}

    /**
     * @return list<string>  Human messages (empty = OK)
     */
    public function validateTaskForCapability(SeoTask $task, WorkflowCapability $capability): array
    {
        $required = $capability->requiredRoles();
        if ($required === []) {
            return [];
        }

        $errors = [];
        $name = trim((string) ($task->name ?? '')) ?: ('#'.(int) $task->getKey());

        foreach ($required as $role) {
            if ($this->roleResolver->findNode($task, $role) !== null) {
                continue;
            }
            $errors[] = sprintf(
                'Workflow «%s» (#%d) thiếu vai trò bắt buộc «%s» (%s) cho capability %s.',
                $name,
                (int) $task->getKey(),
                $role->labelVi(),
                $role->value,
                $capability->labelVi(),
            );
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $pendingSettings  Form state sắp lưu
     * @return list<string>
     */
    public function validatePendingSettings(array $pendingSettings): array
    {
        $errors = [];

        $publishId = $this->positiveInt($pendingSettings[SeoCreateArticleSettingsService::KEY_PUBLISH_ARTICLE] ?? null);
        if ($publishId !== null) {
            $task = SeoTask::query()->find($publishId);
            if (! $task instanceof SeoTask) {
                $errors[] = 'Publish article: Workflow #'.$publishId.' không tồn tại.';
            } else {
                $errors = array_merge(
                    $errors,
                    $this->validateTaskForCapability($task, WorkflowCapability::PublishArticle),
                );
            }
        }

        $mediaChecks = [
            [
                'source' => SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_SOURCE,
                'task' => SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_TASK,
                'capability' => WorkflowCapability::ProductGallery,
            ],
            [
                'source' => SeoCreateArticleSettingsService::KEY_CREATE_TYPOGRAPHY_IMAGE_SOURCE,
                'task' => SeoCreateArticleSettingsService::KEY_CREATE_TYPOGRAPHY_IMAGE_TASK,
                'capability' => WorkflowCapability::TypographyImage,
            ],
            [
                'source' => SeoCreateArticleSettingsService::KEY_CREATE_VIDEO_SOURCE,
                'task' => SeoCreateArticleSettingsService::KEY_CREATE_VIDEO_TASK,
                'capability' => WorkflowCapability::CreateVideo,
            ],
        ];

        foreach ($mediaChecks as $check) {
            $source = (string) ($pendingSettings[$check['source']] ?? '');
            if ($source !== SeoCreateArticleSettingsService::SOURCE_WORKFLOW) {
                continue;
            }
            $taskId = $this->positiveInt($pendingSettings[$check['task']] ?? null);
            if ($taskId === null) {
                $errors[] = $check['capability']->labelVi().': đã chọn Workflow nhưng chưa chọn Task.';

                continue;
            }
            $task = SeoTask::query()->find($taskId);
            if (! $task instanceof SeoTask) {
                $errors[] = $check['capability']->labelVi().': Workflow #'.$taskId.' không tồn tại.';

                continue;
            }
            // Media: không ép image role cứng — chỉ structural validate (duplicate/type) qua doctor soft.
            $structural = $this->roleResolver->validateTask($task);
            foreach ($structural as $msg) {
                if (str_contains($msg, 'trùng') || str_contains($msg, 'không hợp lệ')) {
                    $errors[] = $check['capability']->labelVi().': '.$msg;
                }
            }
        }

        return $errors;
    }

    /**
     * Workflow đang bị Settings bind — role bắt buộc còn đủ trên flow draft?
     *
     * @param  array{nodes?: list<array<string, mixed>>, edges?: mixed}  $flowData
     * @return list<string>
     */
    public function validateFlowPreservesSettingsBindings(SeoTask $task, array $flowData): array
    {
        $taskId = (int) $task->getKey();
        if ($taskId <= 0) {
            return [];
        }

        $bindings = $this->settingsBindingsUsingTask($taskId);
        if ($bindings === []) {
            return [];
        }

        $draft = new SeoTask;
        $draft->id = $taskId;
        $draft->name = $task->name;
        $draft->flow_data = $flowData;

        $errors = [];
        foreach ($bindings as $capability) {
            foreach ($capability->requiredRoles() as $role) {
                if ($this->roleResolver->findNode($draft, $role) !== null) {
                    continue;
                }
                $errors[] = sprintf(
                    'Không thể lưu: Workflow đang được Settings dùng cho «%s» — '
                    .'thiếu vai trò bắt buộc «%s» (%s). Gán lại role trước khi Save.',
                    $capability->labelVi(),
                    $role->labelVi(),
                    $role->value,
                );
            }
        }

        return $errors;
    }

    /**
     * @return list<WorkflowCapability>
     */
    public function settingsBindingsUsingTask(int $taskId): array
    {
        if ($taskId <= 0) {
            return [];
        }

        $settings = $this->settings->getSettings();
        $out = [];

        if ($this->positiveInt($settings[SeoCreateArticleSettingsService::KEY_PUBLISH_ARTICLE] ?? null) === $taskId) {
            $out[] = WorkflowCapability::PublishArticle;
        }

        if ($this->positiveInt($settings[SeoCreateArticleSettingsService::KEY_POST_REVIEW] ?? null) === $taskId) {
            $out[] = WorkflowCapability::PostReview;
        }

        $pairs = [
            [
                SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_SOURCE,
                SeoCreateArticleSettingsService::KEY_CREATE_PRODUCT_GALLERY_TASK,
                WorkflowCapability::ProductGallery,
            ],
            [
                SeoCreateArticleSettingsService::KEY_CREATE_TYPOGRAPHY_IMAGE_SOURCE,
                SeoCreateArticleSettingsService::KEY_CREATE_TYPOGRAPHY_IMAGE_TASK,
                WorkflowCapability::TypographyImage,
            ],
            [
                SeoCreateArticleSettingsService::KEY_CREATE_VIDEO_SOURCE,
                SeoCreateArticleSettingsService::KEY_CREATE_VIDEO_TASK,
                WorkflowCapability::CreateVideo,
            ],
        ];

        foreach ($pairs as [$sourceKey, $taskKey, $capability]) {
            if (($settings[$sourceKey] ?? '') !== SeoCreateArticleSettingsService::SOURCE_WORKFLOW) {
                continue;
            }
            if ($this->positiveInt($settings[$taskKey] ?? null) === $taskId) {
                $out[] = $capability;
            }
        }

        return $out;
    }

    /**
     * Health line cho Settings UI.
     *
     * @return array{ok: bool, message: string, missing_roles: list<string>}
     */
    public function healthForTaskId(?int $taskId, WorkflowCapability $capability): array
    {
        if ($taskId === null || $taskId <= 0) {
            return [
                'ok' => true,
                'message' => '',
                'missing_roles' => [],
            ];
        }

        $task = SeoTask::query()->find($taskId);
        if (! $task instanceof SeoTask) {
            return [
                'ok' => false,
                'message' => '⚠ Workflow #'.$taskId.' không tồn tại',
                'missing_roles' => [],
            ];
        }

        $missing = [];
        foreach ($capability->requiredRoles() as $role) {
            if ($this->roleResolver->findNode($task, $role) === null) {
                $missing[] = $role->labelVi();
            }
        }

        if ($missing === []) {
            return [
                'ok' => true,
                'message' => '✓ Workflow hợp lệ',
                'missing_roles' => [],
            ];
        }

        return [
            'ok' => false,
            'message' => '⚠ Thiếu vai trò: '.implode(', ', $missing),
            'missing_roles' => $missing,
        ];
    }

    private function positiveInt(mixed $value): ?int
    {
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}
