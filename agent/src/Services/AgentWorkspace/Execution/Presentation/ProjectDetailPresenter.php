<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Presentation;

/**
 * User-facing formatter for content_project.get_project / get_status.
 */
final class ProjectDetailPresenter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function present(array $data): array
    {
        $project = is_array($data['project'] ?? null) ? $data['project'] : $data;
        if (! is_array($project) || $project === []) {
            $text = 'Không tìm thấy Content Project.';

            return $this->card($text, $text);
        }

        $id = isset($project['project_id']) && is_numeric($project['project_id'])
            ? (int) $project['project_id']
            : null;
        $name = trim((string) ($project['name'] ?? ''));
        if ($name === '' && isset($data['project_ref'])) {
            $name = 'Content Project';
        }

        $lines = [];
        $lines[] = $id !== null && $name !== ''
            ? $id.' — '.$name
            : ($name !== '' ? $name : 'Chi tiết Content Project');

        $month = $this->formatMonth($project['month'] ?? null);
        if ($month !== null) {
            $lines[] = 'Tháng: '.$month;
        }

        $member = trim((string) ($project['member_name'] ?? $project['assignee_name'] ?? ''));
        if ($member !== '') {
            $lines[] = 'Member: '.$member;
        }

        if (isset($project['archived']) && $project['archived'] === true) {
            $lines[] = 'Trạng thái: Archived';
        } elseif (isset($data['phase']) && is_string($data['phase']) && $data['phase'] !== '') {
            $lines[] = 'Trạng thái: '.ucfirst(str_replace('_', ' ', $data['phase']));
        } elseif (isset($project['status']) && is_string($project['status']) && $project['status'] !== '') {
            $lines[] = 'Trạng thái: '.ucfirst(str_replace('_', ' ', $project['status']));
        }

        $stats = is_array($project['stats'] ?? null) ? $project['stats'] : [];
        if (isset($stats['total_items']) && is_numeric($stats['total_items'])) {
            $lines[] = 'Items: '.(int) $stats['total_items'];
        } elseif (isset($data['phase_counts']) && is_array($data['phase_counts'])) {
            $total = array_sum(array_map('intval', $data['phase_counts']));
            $lines[] = 'Items: '.$total;
            foreach ($data['phase_counts'] as $phase => $count) {
                if (! is_string($phase) || ! is_numeric($count)) {
                    continue;
                }
                $lines[] = '- '.ucfirst(str_replace('_', ' ', $phase)).': '.(int) $count;
            }
        }

        if (isset($data['blockers']) && is_array($data['blockers']) && $data['blockers'] !== []) {
            $lines[] = 'Blockers:';
            foreach ($data['blockers'] as $blocker) {
                if (is_string($blocker) && $blocker !== '') {
                    $lines[] = '- '.$blocker;
                } elseif (is_array($blocker) && isset($blocker['message'])) {
                    $lines[] = '- '.(string) $blocker['message'];
                }
            }
        }

        $text = implode("\n", $lines);

        return $this->card('Content Project', $text);
    }

    private function formatMonth(mixed $month): ?string
    {
        if (! is_string($month) || trim($month) === '') {
            return null;
        }
        $month = trim($month);
        if (preg_match('/^(\d{4})-(\d{2})/', $month, $m) === 1) {
            return $m[2].'/'.$m[1];
        }

        return $month;
    }

    /**
     * @return array<string, mixed>
     */
    private function card(string $title, string $summary): array
    {
        return [
            'title' => $title,
            'summary' => $summary,
            'body' => $summary,
            'user_facing' => true,
            'hide_envelope' => true,
            'badges' => [],
            'links' => [],
            'metrics' => [],
            'warnings' => [],
            'suggested_skills' => [],
            'operation_reference' => null,
            'details' => [],
        ];
    }
}
