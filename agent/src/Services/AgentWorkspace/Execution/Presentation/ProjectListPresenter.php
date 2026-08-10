<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Presentation;

/**
 * User-facing formatter for content_project.list_projects business output.
 * Text-first — portable across Web / Telegram / MCP / API.
 */
final class ProjectListPresenter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function present(array $data): array
    {
        $projects = is_array($data['projects'] ?? null) ? $data['projects'] : [];
        if ($projects === []) {
            $text = 'Chưa có Content Project cho website hiện tại.';

            return $this->card($text, $text);
        }

        $blocks = ['📂 CONTENT PROJECTS'];
        foreach ($projects as $row) {
            if (! is_array($row)) {
                continue;
            }
            $blocks[] = '';
            $blocks[] = $this->formatProjectBlock($row);
        }

        $text = implode("\n", $blocks);

        return $this->card('CONTENT PROJECTS', $text);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function formatProjectBlock(array $row): string
    {
        $id = $this->projectId($row);
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            $name = 'Content Project';
        }

        $header = $id !== null ? '['.$id.'] '.$name : $name;
        $status = $this->statusLabel($row);
        $items = $this->itemCount($row);
        $meta = 'Status: '.$status;
        if ($items !== null) {
            $meta .= ' | Items: '.$items;
        }

        return $header."\n".$meta;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function projectId(array $row): ?int
    {
        if (isset($row['project_id']) && is_numeric($row['project_id'])) {
            return (int) $row['project_id'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function statusLabel(array $row): string
    {
        if (($row['archived'] ?? false) === true) {
            return 'Archived';
        }
        $status = trim((string) ($row['status'] ?? $row['phase'] ?? ''));
        if ($status !== '') {
            return ucfirst(str_replace('_', ' ', $status));
        }

        return 'Active';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function itemCount(array $row): ?int
    {
        if (isset($row['item_count']) && is_numeric($row['item_count'])) {
            return (int) $row['item_count'];
        }
        $stats = is_array($row['stats'] ?? null) ? $row['stats'] : [];
        if (isset($stats['total_items']) && is_numeric($stats['total_items'])) {
            return (int) $stats['total_items'];
        }

        return null;
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
