<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use App\Models\Site;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Seo\Models\SeoArticleProfile;
use Omnichannel\Addons\Seo\Models\SeoFinding;
use Omnichannel\Addons\SiteSync\Services\Heartbeat\WordPressHeartbeatPollService;

final class SeoFindingSyncService
{
    /**
     * Upsert prepared findings from stored snapshots. No crawl.
     *
     * @return list<SeoFinding>
     */
    public function syncFromSnapshots(Site $site): array
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_findings')) {
            return [];
        }

        $siteId = (int) $site->id;
        $seen = [];
        $link = $this->jsonMeta($site, 'seo_link_analysis_snapshot');
        $opportunities = (int) ($link['opportunities'] ?? 0);
        $orphans = (int) ($link['orphan_pages'] ?? 0);
        $broken = (int) ($link['broken_links'] ?? 0);

        if ($broken > 0) {
            $seen[] = $this->upsert($siteId, 'broken_link', 'high', 'site', (string) $siteId, [
                'count' => $broken,
            ], 'Sửa broken internal links.', 'link_analysis');
        }
        if ($orphans > 0) {
            $seen[] = $this->upsert($siteId, 'orphan_page', 'medium', 'site', (string) $siteId, [
                'count' => $orphans,
            ], 'Thêm internal link tới trang mồ côi.', 'link_analysis');
        }
        if ($opportunities > 0) {
            $seen[] = $this->upsert($siteId, 'internal_link_opportunity', 'low', 'site', (string) $siteId, [
                'count' => $opportunities,
            ], 'Áp dụng cơ hội internal link đã chuẩn bị.', 'link_analysis');
        }

        if (Schema::connection('omi_seo_ai')->hasTable('seo_article_profiles')) {
            $missingMeta = SeoArticleProfile::query()
                ->whereHas('article', static fn ($q) => $q->where('site_id', $siteId))
                ->where(function ($q): void {
                    $q->whereNull('meta_description')->orWhere('meta_description', '');
                })
                ->count();
            $missingTitle = SeoArticleProfile::query()
                ->whereHas('article', static fn ($q) => $q->where('site_id', $siteId))
                ->where(function ($q): void {
                    $q->whereNull('seo_title')->orWhere('seo_title', '');
                })
                ->count();
            $noindex = SeoArticleProfile::query()
                ->whereHas('article', static fn ($q) => $q->where('site_id', $siteId))
                ->where('is_indexable', false)
                ->count();
            if ($missingMeta > 0) {
                $seen[] = $this->upsert($siteId, 'missing_meta_description', 'medium', 'site', (string) $siteId, [
                    'count' => $missingMeta,
                ], 'Bổ sung meta description.', 'seo_snapshot');
            }
            if ($missingTitle > 0) {
                $seen[] = $this->upsert($siteId, 'missing_seo_title', 'medium', 'site', (string) $siteId, [
                    'count' => $missingTitle,
                ], 'Bổ sung SEO title.', 'seo_snapshot');
            }
            if ($noindex > 0) {
                $seen[] = $this->upsert($siteId, 'unexpected_noindex', 'critical', 'site', (string) $siteId, [
                    'count' => $noindex,
                ], 'Rà noindex bất thường trên bài indexable.', 'seo_snapshot');
            }
        }

        $heartbeat = $this->jsonMeta($site, WordPressHeartbeatPollService::META_KEY);
        $observedAt = (string) ($heartbeat['observed_at'] ?? '');
        if ($observedAt !== '') {
            try {
                if (\Carbon\Carbon::parse($observedAt)->lt(now()->subHours(48))) {
                    $seen[] = $this->upsert($siteId, 'stale_seo_snapshot', 'info', 'site', (string) $siteId, [
                        'observed_at' => $observedAt,
                    ], 'Làm mới heartbeat / snapshot.', 'heartbeat');
                }
            } catch (\Throwable) {
            }
        }

        $landscape = $this->jsonMeta($site, \Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClassificationService::META_LANDSCAPE);
        $kwFindings = 0;
        foreach ((array) ($landscape['clusters'] ?? []) as $cluster) {
            if (! is_array($cluster) || $kwFindings >= 20) {
                break;
            }
            $coverage = (string) ($cluster['coverage'] ?? '');
            $primary = (string) ($cluster['primary'] ?? $cluster['cluster'] ?? '');
            if ($primary === '') {
                continue;
            }
            $entity = substr($primary, 0, 80);
            if ($coverage === 'missing') {
                $seen[] = $this->upsert($siteId, 'keyword_cluster_missing', 'medium', 'keyword_cluster', $entity, [
                    'cluster' => $primary,
                    'usable' => (int) ($cluster['usable_keyword_count'] ?? 0),
                ], 'Tạo nội dung cho cụm còn thiếu.', 'keyword_landscape');
                $kwFindings++;
            } elseif ($coverage === 'weak') {
                $seen[] = $this->upsert($siteId, 'keyword_cluster_weak', 'low', 'keyword_cluster', $entity, [
                    'cluster' => $primary,
                    'usable' => (int) ($cluster['usable_keyword_count'] ?? 0),
                ], 'Mở rộng coverage cụm yếu.', 'keyword_landscape');
                $kwFindings++;
            } elseif ($coverage === 'saturated') {
                $seen[] = $this->upsert($siteId, 'keyword_cluster_saturated', 'info', 'keyword_cluster', $entity, [
                    'cluster' => $primary,
                    'usable' => (int) ($cluster['usable_keyword_count'] ?? 0),
                ], 'Không sinh thêm keyword paraphrase cho cụm đã bão hòa.', 'keyword_landscape');
                $kwFindings++;
            } elseif (($cluster['intent_gaps'] ?? []) !== []) {
                $seen[] = $this->upsert($siteId, 'keyword_intent_gap', 'low', 'keyword_cluster', $entity, [
                    'cluster' => $primary,
                    'intent_gaps' => $cluster['intent_gaps'],
                ], 'Bổ sung intent còn thiếu trong cụm.', 'keyword_landscape');
                $kwFindings++;
            }
        }

        $openIds = array_filter(array_map(
            static fn ($row) => $row instanceof SeoFinding ? (int) $row->id : 0,
            $seen,
        ));
        SeoFinding::query()
            ->where('site_id', $siteId)
            ->where('status', SeoFinding::STATUS_OPEN)
            ->when($openIds !== [], static fn ($q) => $q->whereNotIn('id', $openIds))
            ->update([
                'status' => SeoFinding::STATUS_RESOLVED,
                'resolved_at' => now(),
            ]);

        return array_values(array_filter($seen, static fn ($row): bool => $row instanceof SeoFinding));
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function upsert(
        int $siteId,
        string $type,
        string $severity,
        string $entityType,
        string $entityId,
        array $evidence,
        string $recommendation,
        string $source,
    ): SeoFinding {
        $fingerprint = hash('sha256', $siteId.'|'.$type.'|'.$entityType.'|'.$entityId);
        $finding = SeoFinding::query()->updateOrCreate(
            ['site_id' => $siteId, 'fingerprint' => $fingerprint],
            [
                'type' => $type,
                'severity' => $severity,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'title' => $type,
                'evidence' => $evidence,
                'recommendation' => $recommendation,
                'source' => $source,
                'status' => SeoFinding::STATUS_OPEN,
                'detected_at' => now(),
                'resolved_at' => null,
            ],
        );

        return $finding;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonMeta(Site $site, string $key): array
    {
        $raw = $site->getMeta($key);
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
