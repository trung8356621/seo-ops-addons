<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\V3;

use App\Models\Site;
use Omnichannel\Addons\Content\Support\ArticleLanguageCode;
use Omnichannel\Addons\Seo\Services\DomainOverviewService;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncV3Schema;
use Omnichannel\Addons\SiteSync\Services\Preflight\SiteSyncPreflightService;
use Omnichannel\Addons\WordPress\Services\SitePolylangService;

/**
 * Builds Domain Overview language-tab snapshots from existing preflight/scoring read models.
 * Does not start sync. Remote discover is opt-in (lazy) via {@see withRemote()}.
 */
final class SiteSyncDomainLanguagePanelService
{
    /**
     * Cheap local snapshot — no WP HTTP.
     *
     * @param  array<string, mixed>|null  $coverageRow  From translationCoverageSummary()
     * @return array<string, mixed>
     */
    public function buildLocalSnapshot(Site $site, string $language, string $role, ?array $coverageRow = null): array
    {
        $language = ArticleLanguageCode::normalize($language);
        $polylang = app(SitePolylangService::class);
        $label = (string) ($coverageRow['label'] ?? $polylang->languageLabel($language, $site));
        $synced = (int) ($coverageRow['synced_to_seo'] ?? 0);
        $available = (int) ($coverageRow['available_on_wp'] ?? 0);
        $isPrimary = $role === SiteSyncV3Schema::LANGUAGE_ROLE_PRIMARY;
        $showFullHealth = $isPrimary || $synced > 0;

        $preflight = null;
        $scoring = null;
        if ($showFullHealth) {
            $preflight = app(SiteSyncPreflightService::class)
                ->evaluateLocalOnly($site, $language !== '' ? $language : null);
            $scoring = app(DomainOverviewService::class)
                ->getWpBackedScoringProgress((int) $site->id, $language !== '' ? $language : null);
        }

        return [
            'language' => $language,
            'label' => $label,
            'flag' => (string) ($coverageRow['flag'] ?? $polylang->languageFlagEmoji($language)),
            'role' => $isPrimary
                ? SiteSyncV3Schema::LANGUAGE_ROLE_PRIMARY
                : SiteSyncV3Schema::LANGUAGE_ROLE_SECONDARY,
            'available_on_wp' => $available,
            'synced_to_seo' => $synced,
            'sync_enabled' => (bool) ($coverageRow['sync_enabled'] ?? $isPrimary),
            'gate_message' => (string) ($coverageRow['gate_message'] ?? ''),
            'gate_code' => (string) ($coverageRow['gate_code'] ?? ''),
            'show_full_health' => $showFullHealth,
            'preflight' => $preflight,
            'scoring' => $scoring,
            'remote_fetched' => false,
            'built_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Merge remote WP counts into an existing local snapshot (one discover call).
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function withRemote(Site $site, array $snapshot): array
    {
        if (! (bool) ($snapshot['show_full_health'] ?? false)) {
            return $snapshot;
        }

        $language = ArticleLanguageCode::normalize((string) ($snapshot['language'] ?? ''));
        $full = app(SiteSyncPreflightService::class)
            ->evaluate($site, $language !== '' ? $language : null);
        $snapshot['preflight'] = $full;
        $snapshot['remote_fetched'] = true;
        $snapshot['built_at'] = now()->toIso8601String();

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>|null  $coverageRow
     * @param  array<string, mixed>|null  $snapshot
     * @return array{
     *   language: string,
     *   language_role: string,
     *   mode: string,
     *   label: string,
     *   estimated_count: int,
     *   title: string,
     *   body: string,
     *   mode_label: string,
     *   confirm_label: string
     * }
     */
    public function buildConfirmPayload(
        Site $site,
        string $language,
        string $role,
        string $mode,
        ?array $coverageRow = null,
        ?array $snapshot = null,
    ): array {
        $language = ArticleLanguageCode::normalize($language);
        $mode = $mode === SiteSyncV3Schema::MODE_FORCE_FULL || $mode === 'force_full'
            ? SiteSyncV3Schema::MODE_FORCE_FULL
            : SiteSyncV3Schema::MODE_DELTA;
        $label = (string) ($coverageRow['label']
            ?? ($snapshot['label'] ?? app(SitePolylangService::class)->languageLabel($language, $site)));
        if ($label === '') {
            $label = $language !== '' ? $language : 'website';
        }

        $estimated = 0;
        if (is_array($snapshot['preflight']['wordpress'] ?? null)) {
            $estimated = (int) ($snapshot['preflight']['wordpress']['total'] ?? 0);
        }
        if ($estimated <= 0 && is_array($snapshot['preflight']['seo_ops'] ?? null)) {
            $estimated = (int) ($snapshot['preflight']['seo_ops']['total'] ?? 0);
        }
        if ($estimated <= 0) {
            $estimated = max(
                (int) ($coverageRow['available_on_wp'] ?? 0),
                (int) ($coverageRow['synced_to_seo'] ?? 0),
            );
        }

        $isFull = $mode === SiteSyncV3Schema::MODE_FORCE_FULL;
        $roleLabel = $role === SiteSyncV3Schema::LANGUAGE_ROLE_SECONDARY ? 'Phụ' : 'Chính';
        $scopeLabel = $label.' · '.$roleLabel;
        $countHint = $estimated > 0
            ? number_format($estimated)
            : 'nhiều';

        return [
            'language' => $language,
            'language_role' => $role === SiteSyncV3Schema::LANGUAGE_ROLE_SECONDARY
                ? SiteSyncV3Schema::LANGUAGE_ROLE_SECONDARY
                : SiteSyncV3Schema::LANGUAGE_ROLE_PRIMARY,
            'mode' => $mode,
            'label' => $label,
            'role_label' => $roleLabel,
            'scope_label' => $scopeLabel,
            'estimated_count' => $estimated,
            'title' => $isFull
                ? 'Đồng bộ lại toàn bộ '.$scopeLabel
                : 'Đồng bộ '.$scopeLabel,
            'body' => $isFull
                ? 'Tác vụ này sẽ duyệt lại toàn bộ nội dung của ngôn ngữ này.'
                : 'Tác vụ nền này có thể xử lý khoảng '.$countHint.' nội dung.',
            'mode_label' => $isFull ? 'Đồng bộ toàn bộ' : 'Đồng bộ thay đổi',
            'confirm_label' => $isFull ? 'Xác nhận đồng bộ toàn bộ' : 'Xác nhận đồng bộ',
        ];
    }
}
