<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent;

/**
 * Keyword heuristics only — no DB, no guessing site/date/counts.
 */
final class ContentProjectNaturalLanguageAdapter
{
    /**
     * @param  array<string, mixed>  $hints
     * @return array{
     *     capability: string|null,
     *     input: array<string, mixed>,
     *     confidence: float,
     *     missing_fields: list<string>,
     *     requires_confirmation: bool,
     *     status: 'needs_input'|'ready'|'ambiguous'
     * }
     */
    public function parseIntent(string $text, array $hints = []): array
    {
        $normalized = mb_strtolower(trim($text));
        $capability = null;
        $input = [];
        $missing = [];
        $requiresConfirmation = false;
        $confidence = 0.0;

        if (isset($hints['project_ref']) && is_string($hints['project_ref'])) {
            $input['project_ref'] = $hints['project_ref'];
        }

        if (isset($hints['item_refs']) && is_array($hints['item_refs'])) {
            $input['item_refs'] = $hints['item_refs'];
        }

        if (str_contains($normalized, 'archive') || str_contains($normalized, 'lưu trữ')) {
            $capability = 'content_project.archive';
            $requiresConfirmation = true;
            $confidence = 0.7;
        } elseif (str_contains($normalized, 'restore') || str_contains($normalized, 'khôi phục')) {
            $capability = 'content_project.restore';
            $requiresConfirmation = true;
            $confidence = 0.7;
        } elseif (str_contains($normalized, 'publish') || str_contains($normalized, 'xuất bản')) {
            $capability = 'content_project.publish_now';
            $requiresConfirmation = true;
            $confidence = 0.65;
        } elseif (str_contains($normalized, 'schedule') || str_contains($normalized, 'lên lịch')) {
            $capability = 'content_project.schedule';
            $missing[] = 'scheduled_at';
            $confidence = 0.6;
        } elseif (str_contains($normalized, 'approve') || str_contains($normalized, 'duyệt')) {
            $capability = 'content_project.approve';
            $confidence = 0.65;
        } elseif (str_contains($normalized, 'review')) {
            $capability = 'content_project.start_review';
            $confidence = 0.6;
        } elseif (str_contains($normalized, 'generate') || str_contains($normalized, 'tạo bài')) {
            $capability = 'content_project.generate';
            $confidence = 0.65;
        } elseif (
            str_contains($normalized, 'create project')
            || str_contains($normalized, 'tạo project')
            || str_contains($normalized, 'tạo content project')
            || (str_contains($normalized, 'tạo') && str_contains($normalized, 'project'))
        ) {
            $capability = 'content_project.create';
            $confidence = 0.7;
            $missing[] = 'site_ref';
            $missing[] = 'project_name';
        } elseif (str_contains($normalized, 'rerun') || str_contains($normalized, 'chạy lại')) {
            $capability = 'content_project.rerun_items';
            $confidence = 0.6;
        } elseif (str_contains($normalized, 'status') || str_contains($normalized, 'trạng thái')) {
            $capability = 'content_project.get_status';
            $confidence = 0.75;
        } elseif (str_contains($normalized, 'list project') || str_contains($normalized, 'danh sách project')) {
            $capability = 'content_project.list_projects';
            $confidence = 0.8;
        } elseif (str_contains($normalized, 'queue') || str_contains($normalized, 'hàng đợi')) {
            $capability = 'content_project.get_publishing_queue';
            $confidence = 0.7;
        }

        if (isset($hints['site_ref']) && is_string($hints['site_ref']) && $hints['site_ref'] !== '') {
            $input['site_ref'] = $hints['site_ref'];
            $missing = array_values(array_filter($missing, static fn (string $f): bool => $f !== 'site_ref'));
        }

        if ($capability !== null && ! isset($input['project_ref'])
            && ! in_array($capability, [
                'content_project.list_projects',
                'content_project.get_site_health',
                'content_project.get_daily_report',
                'content_project.create',
            ], true)) {
            $missing[] = 'project_ref';
        }

        if ($capability === 'content_project.publish_now' && ! isset($input['item_refs'])) {
            $missing[] = 'item_refs';
        }

        $status = 'ready';
        if ($capability === null) {
            $status = 'ambiguous';
            $confidence = 0.2;
        } elseif ($missing !== []) {
            $status = 'needs_input';
        }

        return [
            'capability' => $capability,
            'input' => $input,
            'confidence' => $confidence,
            'missing_fields' => $missing,
            'requires_confirmation' => $requiresConfirmation,
            'status' => $status,
        ];
    }
}
