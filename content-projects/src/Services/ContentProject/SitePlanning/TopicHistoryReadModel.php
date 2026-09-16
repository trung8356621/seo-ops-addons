<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectTopicHistory;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;

/**
 * Reads Topic History only — never reconstructs from projects/articles.
 */
final class TopicHistoryReadModel
{
    /**
     * Month Detail: topics grouped with SUM(planned_article_count).
     *
     * @return array{
     *   site_id: int,
     *   domain: string,
     *   planning_month: string,
     *   month_label: string,
     *   topics: list<array{topic_ref: string|null, topic_name: string, planned_article_count: int}>
     * }
     */
    public function forSiteMonth(int $siteId, string $planningMonth): array
    {
        $month = ContentProjectMonthContext::normalize($planningMonth);
        $domain = $this->resolveDomain($siteId);
        $empty = [
            'site_id' => $siteId,
            'domain' => $domain,
            'planning_month' => $month,
            'month_label' => ContentProjectMonthContext::display($month),
            'topics' => [],
        ];

        if ($siteId <= 0 || ! Schema::connection('omi_seo_ai')->hasTable('seo_content_project_topic_histories')) {
            return $empty;
        }

        $monthDate = ContentProjectMonthContext::toDateString($month);
        $rows = SeoContentProjectTopicHistory::query()
            ->where('site_id', $siteId)
            ->whereDate('planning_month', $monthDate)
            ->orderBy('id')
            ->get(['topic_ref', 'topic_name', 'planned_article_count']);

        $buckets = [];
        foreach ($rows as $row) {
            $ref = trim((string) ($row->topic_ref ?? ''));
            $key = $ref !== '' ? $ref : 'name:'.mb_strtolower(trim((string) $row->topic_name), 'UTF-8');
            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'topic_ref' => $ref !== '' ? $ref : null,
                    'topic_name' => trim((string) $row->topic_name),
                    'planned_article_count' => 0,
                ];
            }
            $buckets[$key]['planned_article_count'] += max(0, (int) $row->planned_article_count);
            if ($buckets[$key]['topic_name'] === '' && trim((string) $row->topic_name) !== '') {
                $buckets[$key]['topic_name'] = trim((string) $row->topic_name);
            }
        }

        $topics = array_values($buckets);
        usort(
            $topics,
            static fn (array $a, array $b): int => ((int) $b['planned_article_count']) <=> ((int) $a['planned_article_count']),
        );

        return [
            'site_id' => $siteId,
            'domain' => $domain,
            'planning_month' => $month,
            'month_label' => ContentProjectMonthContext::display($month),
            'topics' => $topics,
        ];
    }

    /**
     * Suggest Notes overlay: topic_ref => SUM(planned_article_count) for the site.
     *
     * @return array<string, int>
     */
    public function plannedCountsByTopicRef(int $siteId): array
    {
        if ($siteId <= 0 || ! Schema::connection('omi_seo_ai')->hasTable('seo_content_project_topic_histories')) {
            return [];
        }

        $rows = SeoContentProjectTopicHistory::query()
            ->where('site_id', $siteId)
            ->whereNotNull('topic_ref')
            ->where('topic_ref', '!=', '')
            ->selectRaw('topic_ref, SUM(planned_article_count) as total')
            ->groupBy('topic_ref')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $ref = trim((string) ($row->topic_ref ?? ''));
            if ($ref === '') {
                continue;
            }
            $out[$ref] = max(0, (int) ($row->total ?? 0));
        }

        return $out;
    }

    private function resolveDomain(int $siteId): string
    {
        if ($siteId <= 0) {
            return '';
        }
        $site = \App\Models\Site::query()->find($siteId, ['id', 'domain']);
        $domain = trim((string) ($site?->domain ?? ''));

        return $domain !== '' ? $domain : '#'.$siteId;
    }
}
