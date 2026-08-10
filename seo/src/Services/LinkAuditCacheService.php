<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use Omnichannel\Addons\Seo\Enums\SeoLinkMapStatus;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkAudit;
use Omnichannel\Addons\SearchFoundation\Models\SeoLinkMap;

final class LinkAuditCacheService
{
    public function ttlHours(): int
    {
        return max(1, (int) config('seo-content-ai.link_audit_cache_ttl_hours', 24));
    }

    public function normalizeUrl(string $url): string
    {
        $url = trim($url);

        return rtrim(strtolower($url), '/');
    }

    public function hashUrl(string $url): string
    {
        return hash('sha256', $this->normalizeUrl($url));
    }

    public function findFresh(int $siteId, string $targetUrl): ?SeoLinkAudit
    {
        if ($siteId <= 0 || trim($targetUrl) === '') {
            return null;
        }

        $audit = SeoLinkAudit::query()
            ->where('site_id', $siteId)
            ->where('target_url_hash', $this->hashUrl($targetUrl))
            ->first();

        if (! $audit instanceof SeoLinkAudit) {
            return null;
        }

        if ($audit->last_audited_at === null) {
            return null;
        }

        if ($audit->last_audited_at->lt(now()->subHours($this->ttlHours()))) {
            return null;
        }

        return $audit;
    }

    public function applyToLinkMap(SeoLinkMap $linkMap, SeoLinkAudit $audit): void
    {
        $linkMap->update([
            'status' => $audit->status instanceof SeoLinkMapStatus ? $audit->status : SeoLinkMapStatus::Active,
            'last_http_status' => $audit->last_http_status,
            'last_audited_at' => $audit->last_audited_at,
        ]);
    }

    public function upsertFromLinkMap(
        int $siteId,
        string $targetUrl,
        SeoLinkMapStatus $status,
        ?int $httpStatus,
        ?\DateTimeInterface $auditedAt = null,
    ): SeoLinkAudit {
        $auditedAt ??= now();

        return SeoLinkAudit::query()->updateOrCreate(
            [
                'site_id' => $siteId,
                'target_url_hash' => $this->hashUrl($targetUrl),
            ],
            [
                'target_url' => $targetUrl,
                'status' => $status,
                'last_http_status' => $httpStatus,
                'last_audited_at' => $auditedAt,
            ],
        );
    }
}
