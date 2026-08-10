<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectRerunFromStep;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunAction;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\Workflow\ArtifactReusePolicy;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves «Tiếp tục từ bước lỗi» from latest run-item attempt — fail closed.
 *
 * @phpstan-type ResumePlan array{
 *   ok: bool,
 *   from_step: ?ContentProjectRerunFromStep,
 *   include_downstream: bool,
 *   resumed_from_step: ?string,
 *   reused_steps: list<string>,
 *   invalidated_steps: list<string>,
 *   failed_step_key: ?string,
 *   run_item_id: ?int,
 *   attempt: ?int,
 *   message: string
 * }
 */
final class ContentProjectFailedStepResumeResolver
{
    public function __construct(
        private readonly ArtifactReusePolicy $reusePolicy,
    ) {}

    /**
     * @return ResumePlan
     */
    public function resolve(SeoProjectTask $task): array
    {
        $taskId = (int) $task->getKey();
        if ($taskId <= 0) {
            return $this->fail('Missing project task id.');
        }

        $latest = $this->latestRunItem($taskId);
        if (! $latest instanceof SeoProjectRunItem) {
            return $this->fail('Không tìm thấy latest run-item attempt — resume fail-closed.');
        }

        $status = strtolower(trim((string) ($latest->status ?? '')));
        if (! in_array($status, ['failed', 'error', 'cancelled', 'stopped', 'timeout'], true)) {
            return $this->fail('Latest attempt không ở trạng thái failed — không resume.');
        }

        $failedStepKey = $this->resolveFailedStepKey($latest);
        if ($failedStepKey === null) {
            return $this->fail('Không resolve được failed_step_key từ latest attempt — resume fail-closed.');
        }

        $fromStep = $this->mapFailedStepToRerunFrom($failedStepKey);
        if (! $fromStep instanceof ContentProjectRerunFromStep) {
            return $this->fail('failed_step_key không map được sang outline|article — resume fail-closed.');
        }

        $reused = [];
        $invalidated = [$fromStep->value];
        if ($fromStep === ContentProjectRerunFromStep::Article) {
            $reused[] = ContentProjectRerunFromStep::Outline->value;
            $invalidated = [ContentProjectRerunFromStep::Article->value];
        } else {
            $invalidated = [
                ContentProjectRerunFromStep::Outline->value,
                ContentProjectRerunFromStep::Article->value,
            ];
        }

        return [
            'ok' => true,
            'from_step' => $fromStep,
            'include_downstream' => $fromStep === ContentProjectRerunFromStep::Outline,
            'resumed_from_step' => $fromStep->value,
            'reused_steps' => $reused,
            'invalidated_steps' => $invalidated,
            'failed_step_key' => $failedStepKey,
            'run_item_id' => (int) $latest->getKey(),
            'attempt' => isset($latest->attempt) ? (int) $latest->attempt : null,
            'message' => 'Resume from failed step: '.$fromStep->value,
            'input_fingerprint_policy' => $this->reusePolicy::class,
        ];
    }

    private function latestRunItem(int $taskId): ?SeoProjectRunItem
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_project_run_items')) {
            return null;
        }

        return SeoProjectRunItem::query()
            ->where('task_id', $taskId)
            ->orderByDesc('id')
            ->first();
    }

    private function resolveFailedStepKey(SeoProjectRunItem $item): ?string
    {
        // 1) Error / message — UI SoT for failed prompt (e.g. «Dàn ý… outline.generate»).
        // Never trust run_item.action here: values like article.create are pipeline kind, not workflow step.
        $fromError = $this->classifyErrorMessage((string) ($item->error_message ?? ''));
        if ($fromError !== null) {
            return $fromError;
        }

        $snapshot = is_array($item->output_snapshot ?? null) ? $item->output_snapshot : [];

        // 2) First failed step in snapshot (hook/role/title).
        $steps = is_array($snapshot['steps'] ?? null) ? $snapshot['steps'] : [];
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }
            if (($step['status'] ?? '') !== 'failed') {
                continue;
            }
            $mapped = $this->classifyStepArray($step);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        // 3) Explicit step keys in snapshot only (not pipeline action).
        foreach (['failed_step', 'failed_step_key', 'rerun_from_step', 'current_step'] as $key) {
            $raw = strtolower(trim((string) ($snapshot[$key] ?? '')));
            if ($raw === '' || $this->isPipelineActionToken($raw)) {
                continue;
            }
            $mapped = $this->normalizeStepToken($raw);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        // 4) action only when it is a step:* token — never article.create / rewrite / …
        $action = strtolower(trim((string) ($item->action ?? '')));
        if ($action !== '' && ! $this->isPipelineActionToken($action)) {
            if (str_starts_with($action, 'step:')) {
                $mapped = $this->normalizeStepToken(substr($action, 5));
                if ($mapped !== null) {
                    return $mapped;
                }
            }
            $mapped = $this->normalizeStepToken($action);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        return null;
    }

    private function classifyErrorMessage(string $error): ?string
    {
        $error = strtolower(trim($error));
        if ($error === '') {
            return null;
        }

        // Outline failures (TEXT_OUTSIDE_DECLARED_SECTIONS, missing markers, …).
        if (str_contains($error, 'outline.generate')
            || str_contains($error, 'article.outline')
            || str_contains($error, 'text_outside_declared_sections')
            || str_contains($error, 'không tìm thấy outline')
            || str_contains($error, 'outline để tạo lại')
            || (str_contains($error, 'dàn ý') && ! str_contains($error, 'theo dàn ý') && ! str_contains($error, 'viết bài'))
            || str_contains($error, 'khối prompt — dàn ý')
        ) {
            return ContentProjectRerunFromStep::Outline->value;
        }

        // Content / write failures.
        if (str_contains($error, 'content.generate')
            || str_contains($error, 'content.rewrite')
            || str_contains($error, 'article.content')
            || str_contains($error, 'unknown input key')
            || str_contains($error, 'viết bài')
            || str_contains($error, 'theo dàn ý')
        ) {
            return ContentProjectRerunFromStep::Article->value;
        }

        // Bare «topic» alone is ambiguous — only with content-ish context.
        if (str_contains($error, 'topic') && (
            str_contains($error, 'input') || str_contains($error, 'content')
        )) {
            return ContentProjectRerunFromStep::Article->value;
        }

        return null;
    }

    private function isPipelineActionToken(string $raw): bool
    {
        $raw = strtolower(trim($raw));
        if (in_array($raw, SeoProjectRunAction::values(), true)) {
            return true;
        }

        return in_array($raw, [
            'article.improve',
            'create',
            'rewrite',
            'improve',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function classifyStepArray(array $step): ?string
    {
        $role = strtolower(trim((string) ($step['execution_role'] ?? '')));
        $hook = strtolower(trim((string) ($step['hook_key'] ?? '')));
        $title = strtolower(trim((string) ($step['title'] ?? '')));

        // Hook / role are authoritative — title «Viết bài theo dàn ý» must NOT become outline.
        if (str_contains($hook, 'content.') || str_contains($hook, '.content')
            || str_contains($role, 'content')) {
            return ContentProjectRerunFromStep::Article->value;
        }
        if (str_contains($hook, 'outline') || str_contains($role, 'outline')) {
            return ContentProjectRerunFromStep::Outline->value;
        }

        // Title: content-write phrases win over embedded «dàn ý» (input source, not step kind).
        if (str_contains($title, 'viết bài') || str_contains($title, 'write article')
            || str_contains($title, 'theo dàn ý') || str_contains($title, 'from outline')) {
            return ContentProjectRerunFromStep::Article->value;
        }
        if (str_contains($title, 'dàn ý') || str_contains($title, 'outline')) {
            return ContentProjectRerunFromStep::Outline->value;
        }

        return $this->normalizeStepToken((string) ($step['node_id'] ?? $title));
    }

    private function normalizeStepToken(string $raw): ?string
    {
        $raw = strtolower(trim($raw));
        if ($raw === '' || $this->isPipelineActionToken($raw)) {
            return null;
        }

        // «theo dàn ý» / write-from-outline = article step.
        if (str_contains($raw, 'theo dàn ý') || str_contains($raw, 'from outline')
            || str_contains($raw, 'viết bài') || str_contains($raw, 'write')) {
            return ContentProjectRerunFromStep::Article->value;
        }

        if (str_contains($raw, 'content') || str_contains($raw, 'viết')) {
            return ContentProjectRerunFromStep::Article->value;
        }

        // Bare «article» alone is ambiguous (pipeline article.create) — only accept explicit step enums.
        if ($raw === 'article' || $raw === 'content') {
            return ContentProjectRerunFromStep::Article->value;
        }

        if (str_contains($raw, 'outline') || str_contains($raw, 'dàn ý') || str_contains($raw, 'dan y')) {
            return ContentProjectRerunFromStep::Outline->value;
        }

        return ContentProjectRerunFromStep::tryFromMixed($raw)?->value;
    }

    private function mapFailedStepToRerunFrom(string $failedStepKey): ?ContentProjectRerunFromStep
    {
        return ContentProjectRerunFromStep::tryFromMixed($failedStepKey);
    }

    /**
     * @return ResumePlan
     */
    private function fail(string $message): array
    {
        return [
            'ok' => false,
            'from_step' => null,
            'include_downstream' => false,
            'resumed_from_step' => null,
            'reused_steps' => [],
            'invalidated_steps' => [],
            'failed_step_key' => null,
            'run_item_id' => null,
            'attempt' => null,
            'message' => $message,
        ];
    }
}
