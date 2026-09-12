<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Illuminate\Support\Carbon;

/**
 * Build Legacy-style date-grouped dashboard from canonical archive presenter rows.
 */
final class CanonicalArchiveListDashboardBuilder
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{
     *     groups: list<array{date: string, date_label: string, month_key: string, month_label: string, count: int, articles: list<array<string, mixed>>}>,
     *     month_options: list<array{value: string, label: string}>,
     *     domain_options: list<array{value: int, label: string}>
     * }
     */
    public function build(array $rows): array
    {
        /** @var array<string, array{date: string, date_label: string, month_key: string, month_label: string, count: int, articles: list<array<string, mixed>>}> $grouped */
        $grouped = [];
        /** @var array<string, string> $monthLabels */
        $monthLabels = [];
        /** @var array<int, string> $domainLabels */
        $domainLabels = [];

        foreach ($rows as $row) {
            $completedRaw = $row['completed_at_raw'] ?? $row['archived_at_raw'] ?? $row['completed_at'] ?? null;
            $completedAt = $this->parseCarbon($completedRaw);
            if (! $completedAt instanceof Carbon) {
                continue;
            }

            $dateKey = $completedAt->toDateString();
            $monthKey = $completedAt->format('Y-m');
            $monthLabels[$monthKey] = $completedAt->format('m/Y');

            if (! isset($grouped[$dateKey])) {
                $grouped[$dateKey] = [
                    'date' => $dateKey,
                    'date_label' => $completedAt->translatedFormat('d/m/Y'),
                    'month_key' => $monthKey,
                    'month_label' => $monthLabels[$monthKey],
                    'count' => 0,
                    'articles' => [],
                ];
            }

            $siteId = (int) ($row['site_id'] ?? 0);
            $domain = trim((string) ($row['domain'] ?? ''));
            if ($siteId > 0 && $domain !== '' && $domain !== '—') {
                $domainLabels[$siteId] = $domain;
            }

            $grouped[$dateKey]['articles'][] = [
                'id' => (int) ($row['article_id'] ?? 0),
                'item_id' => (int) ($row['item_id'] ?? 0),
                'archive_item_id' => (int) ($row['item_id'] ?? 0),
                'site_id' => $siteId,
                'domain' => $domain !== '' ? $domain : '—',
                'title' => (string) ($row['title'] ?? ''),
                'author' => (string) ($row['author'] ?? '—'),
                'keyword' => (string) ($row['keyword'] ?? ''),
                'project_label' => null,
                'completed_time' => $completedAt->format('H:i'),
                'completed_at_label' => $completedAt->format('d/m/Y H:i'),
                'completed_by' => (string) ($row['completed_by'] ?? '—'),
                'edit_url' => $row['edit_url'] ?? null,
                'view_url' => (($row['has_public_wordpress_url'] ?? false) ? ($row['wordpress_url'] ?? null) : null),
                'wordpress_url' => (string) ($row['wordpress_url'] ?? ''),
                'has_public_wordpress_url' => (bool) ($row['has_public_wordpress_url'] ?? false),
                'check_index_url' => $row['check_index_url'] ?? null,
                'indexed_at_label' => $row['indexed_at_label'] ?? null,
                'social_links_count' => (int) ($row['social_links_count'] ?? 0),
                'seo_score' => $row['seo_score'] ?? null,
                'article_exists' => (bool) ($row['article_exists'] ?? false),
                'can_edit' => (bool) ($row['can_edit'] ?? false),
            ];
            $grouped[$dateKey]['count']++;
        }

        krsort($monthLabels);
        $monthOptions = [];
        foreach ($monthLabels as $value => $label) {
            $monthOptions[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        ksort($domainLabels);
        $domainOptions = [];
        foreach ($domainLabels as $value => $label) {
            $domainOptions[] = ['value' => (int) $value, 'label' => (string) $label];
        }

        krsort($grouped);

        return [
            'groups' => array_values($grouped),
            'month_options' => $monthOptions,
            'domain_options' => $domainOptions,
        ];
    }

    private function parseCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
