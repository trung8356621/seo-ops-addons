<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\SiteContext\Readers;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Seo\Models\SeoArticleProfile;
use Omnichannel\Addons\Seo\Models\SeoFinding;

/**
 * SEO findings + local article indexability flags for Site Intelligence Context (human/UI workflows).
 *
 * indexability() is internal workflow state (seo_article_profiles.is_indexable) — not Google
 * index coverage. It must not be registered as an AI/MCP Context slice.
 */
final class SiteSeoHealthReader
{
    /**
     * @return array{critical: int, high: int, top: list<array<string, mixed>>, updated_at: ?string}
     */
    public function findings(int $siteId): array
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_findings')) {
            return ['critical' => 0, 'high' => 0, 'top' => [], 'updated_at' => null];
        }
        $open = SeoFinding::query()
            ->where('site_id', $siteId)
            ->where('status', SeoFinding::STATUS_OPEN)
            ->orderByDesc('id')
            ->get();
        $top = [];
        foreach ($open->take(10) as $finding) {
            $top[] = [
                'id' => (int) $finding->id,
                'type' => (string) $finding->type,
                'severity' => (string) $finding->severity,
                'title' => (string) $finding->title,
            ];
        }
        $updated = $open->max('updated_at');

        return [
            'critical' => $open->where('severity', 'critical')->count(),
            'high' => $open->where('severity', 'high')->count(),
            'top' => $top,
            'updated_at' => $updated !== null ? (string) $updated : null,
        ];
    }

    /**
     * @return array{indexable: int, noindex: int}
     */
    public function indexability(int $siteId): array
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_article_profiles')) {
            return ['indexable' => 0, 'noindex' => 0];
        }

        return [
            'indexable' => (int) SeoArticleProfile::query()
                ->whereHas('article', static fn ($q) => $q->where('site_id', $siteId))
                ->where('is_indexable', true)
                ->count(),
            'noindex' => (int) SeoArticleProfile::query()
                ->whereHas('article', static fn ($q) => $q->where('site_id', $siteId))
                ->where('is_indexable', false)
                ->count(),
        ];
    }
}
