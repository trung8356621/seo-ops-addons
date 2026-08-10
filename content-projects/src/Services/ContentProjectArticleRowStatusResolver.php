<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectArticleRowStatus;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionStatus;
use Illuminate\Support\Carbon;

/**
 * Row status semantic — không dùng updated_at chung.
 * Active chỉ thắng khi SoT / busy step xác nhận execution thực sự active.
 */
final class ContentProjectArticleRowStatusResolver
{
    /**
     * @param  array<string, mixed>  $item  Run display item (đã enrich workflow_steps / last_saved)
     */
    public function resolve(array $item, ?SeoArticle $article = null): ContentProjectArticleRowStatus
    {
        $steps = is_array($item['workflow_steps'] ?? null) ? $item['workflow_steps'] : [];
        $activeMeta = is_array($item['active_execution'] ?? null) ? $item['active_execution'] : null;
        $hasCanonicalActive = $activeMeta !== null
            && ContentProjectExecutionStatus::isActive((string) ($activeMeta['status'] ?? ''))
            && empty($activeMeta['finished_at']);

        $busy = [];
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }
            $stepStatus = strtolower(trim((string) ($step['status'] ?? '')));
            $stepBusy = (bool) ($step['busy'] ?? false);
            // busy chỉ tin khi status step cũng active (tránh false busy từ query lệch).
            if ($stepBusy && ($stepStatus === '' || ContentProjectExecutionStatus::isActive($stepStatus))) {
                $busy[] = $step;
            }
        }

        if ($hasCanonicalActive || $busy !== []) {
            $label = trim((string) (
                ($busy[0]['label'] ?? null)
                ?? ($activeMeta['node_id'] ?? null)
                ?? 'Bước'
            ));

            return new ContentProjectArticleRowStatus(
                code: ContentProjectArticleRowStatus::CODE_RUNNING,
                label: 'Đang chạy: '.$label,
                tooltip: 'Đang có execution active cho bài này.',
                stepLabel: $label !== '' ? $label : null,
            );
        }

        $rawStatus = strtolower(trim((string) ($item['status'] ?? '')));
        $persistStatus = strtolower(trim((string) ($item['persist_status'] ?? $item['latest_persist_status'] ?? '')));

        if ($persistStatus === 'ignored_stale' || $rawStatus === 'ignored_stale') {
            return new ContentProjectArticleRowStatus(
                code: ContentProjectArticleRowStatus::CODE_IGNORED_STALE,
                label: 'Bỏ qua kết quả AI cũ',
                tooltip: 'Kết quả AI không được ghi đè vì bài viết đã thay đổi trong lúc xử lý.',
            );
        }

        if (in_array($rawStatus, ['failed', 'error'], true)) {
            $failedStep = $this->latestFailedStepLabel($steps, $item);

            return new ContentProjectArticleRowStatus(
                code: ContentProjectArticleRowStatus::CODE_FAILED,
                label: $failedStep !== null ? 'Lỗi: '.$failedStep : 'Lỗi',
                tooltip: trim((string) ($item['error_message'] ?? $item['message'] ?? '')) ?: null,
                stepLabel: $failedStep,
            );
        }

        if ($this->isManualEditAfterAi($item, $article)) {
            return new ContentProjectArticleRowStatus(
                code: ContentProjectArticleRowStatus::CODE_MANUAL_EDIT,
                label: 'Đã sửa thủ công',
                tooltip: 'Bài viết đã được chỉnh sửa sau lần AI cập nhật gần nhất.',
            );
        }

        if (in_array($rawStatus, ['success', 'completed', 'done'], true)) {
            return new ContentProjectArticleRowStatus(
                code: ContentProjectArticleRowStatus::CODE_COMPLETED,
                label: 'Hoàn tất',
            );
        }

        if (in_array($rawStatus, ['pending', 'queued', 'waiting', 'manual'], true) || $rawStatus === '') {
            return new ContentProjectArticleRowStatus(
                code: ContentProjectArticleRowStatus::CODE_PENDING,
                label: 'Đang chờ',
            );
        }

        return new ContentProjectArticleRowStatus(
            code: ContentProjectArticleRowStatus::CODE_PENDING,
            label: 'Đang chờ',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, mixed>  $item
     */
    private function latestFailedStepLabel(array $steps, array $item): ?string
    {
        foreach (array_reverse($steps) as $step) {
            if (! is_array($step)) {
                continue;
            }
            $status = strtolower(trim((string) ($step['status'] ?? '')));
            if (in_array($status, ['failed', 'error'], true)) {
                $label = trim((string) ($step['label'] ?? ''));

                return $label !== '' ? $label : null;
            }
        }

        $msg = trim((string) ($item['error_message'] ?? ''));
        if ($msg !== '' && str_contains(mb_strtolower($msg), 'dàn ý')) {
            return 'Dàn ý';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isManualEditAfterAi(array $item, ?SeoArticle $article): bool
    {
        $manual = $this->asCarbon(
            $article?->last_manual_saved_at ?? ($item['last_manual_saved_at'] ?? null)
        );
        $ai = $this->asCarbon(
            $article?->last_ai_content_at ?? ($item['last_ai_content_at'] ?? null)
        );

        if (! $manual instanceof Carbon) {
            return false;
        }

        if (! $ai instanceof Carbon) {
            // Manual save tồn tại, chưa có AI persist — coi là đã sửa thủ công nếu status completed.
            return in_array(strtolower(trim((string) ($item['status'] ?? ''))), ['success', 'completed'], true);
        }

        return $manual->gt($ai);
    }

    private function asCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }
        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
