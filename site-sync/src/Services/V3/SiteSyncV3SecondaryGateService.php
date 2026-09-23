<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\V3;

use App\Models\Site;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticleContentClassification;
use Omnichannel\Addons\Content\Support\ArticleLanguageCode;
use Omnichannel\Addons\SiteSync\Models\SeoSiteSyncRun;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncV3Schema;
use Omnichannel\Addons\SiteSync\Services\Inbound\WordPressSiteSyncV3Client;
use Omnichannel\Addons\WordPress\Services\ArticlePolylangSyncService;
use Omnichannel\Addons\WordPress\Services\SitePrimaryLanguageService;
use Omnichannel\Addons\WordPress\Services\SitePolylangService;

/**
 * Secondary-language sync gate + primary freshness + translation coverage summary.
 *
 * Primary completion never depends on secondary import/scoring.
 */
final class SiteSyncV3SecondaryGateService
{
    public function __construct(
        private readonly SiteSyncV3LanguageScope $languageScope = new SiteSyncV3LanguageScope(),
        private readonly SiteSyncV3CheckpointStore $checkpoints = new SiteSyncV3CheckpointStore(),
        private readonly SitePrimaryLanguageService $primaryLanguage = new SitePrimaryLanguageService(),
        private readonly WordPressSiteSyncV3Client $client = new WordPressSiteSyncV3Client(),
    ) {}

    /**
     * @return array{
     *   allowed: bool,
     *   reason: string,
     *   code: string,
     *   primary_language: string|null,
     *   secondary_language: string,
     *   message: string
     * }
     */
    public function evaluateSecondarySync(Site $site, string $secondaryLanguage): array
    {
        $secondaryLanguage = ArticleLanguageCode::normalize($secondaryLanguage);
        $primary = $this->languageScope->primaryLanguage($site);
        $labelPrimary = $primary !== null
            ? app(SitePolylangService::class)->languageLabel($primary, $site)
            : 'ngôn ngữ chính';
        $labelSecondary = app(SitePolylangService::class)->languageLabel($secondaryLanguage, $site);

        $base = [
            'primary_language' => $primary,
            'secondary_language' => $secondaryLanguage,
        ];

        if (! $this->languageScope->isMultilingual($site)) {
            return $base + [
                'allowed' => false,
                'reason' => 'not_multilingual',
                'code' => 'not_multilingual',
                'message' => 'Site không dùng Polylang — không có ngôn ngữ phụ.',
            ];
        }

        if ($secondaryLanguage === '' || ($primary !== null && $secondaryLanguage === $primary)) {
            return $base + [
                'allowed' => false,
                'reason' => 'invalid_secondary',
                'code' => 'invalid_secondary',
                'message' => 'Ngôn ngữ phụ không hợp lệ.',
            ];
        }

        $active = SeoSiteSyncRun::query()
            ->where('site_id', (int) $site->id)
            ->whereIn('status', ['pending', 'running'])
            ->first();
        if ($active !== null) {
            return $base + [
                'allowed' => false,
                'reason' => 'site_sync_busy',
                'code' => 'site_sync_busy',
                'message' => 'Đang có Site Sync chạy cho website này. Chờ hoàn tất trước.',
            ];
        }

        if ($primary === null || ! $this->checkpoints->hasSuccessfulBaseline($site, $primary)) {
            return $base + [
                'allowed' => false,
                'reason' => 'primary_incomplete',
                'code' => 'primary_incomplete',
                'message' => "Chưa thể đồng bộ {$labelSecondary}. Hãy hoàn tất đồng bộ {$labelPrimary} trước.",
            ];
        }

        $latestPrimary = $this->latestScopedRun($site, $primary, SiteSyncV3Schema::LANGUAGE_ROLE_PRIMARY);
        if ($latestPrimary === null) {
            return $base + [
                'allowed' => false,
                'reason' => 'primary_incomplete',
                'code' => 'primary_incomplete',
                'message' => "Chưa thể đồng bộ {$labelSecondary}. Hãy hoàn tất đồng bộ {$labelPrimary} trước.",
            ];
        }

        $status = (string) $latestPrimary->status;
        if (in_array($status, ['failed', 'needs_attention'], true)) {
            return $base + [
                'allowed' => false,
                'reason' => 'primary_failed',
                'code' => 'primary_failed',
                'message' => "Chưa thể đồng bộ {$labelSecondary}. Hãy hoàn tất đồng bộ {$labelPrimary} trước.",
            ];
        }

        if (! in_array($status, ['completed', 'completed_with_warnings'], true)) {
            return $base + [
                'allowed' => false,
                'reason' => 'primary_running',
                'code' => 'primary_running',
                'message' => "Chưa thể đồng bộ {$labelSecondary}. Hãy hoàn tất đồng bộ {$labelPrimary} trước.",
            ];
        }

        $meta = is_array($latestPrimary->meta) ? $latestPrimary->meta : [];
        $verify = is_array($meta['verify'] ?? null) ? $meta['verify'] : [];
        $hasVerifyGap = ($verify['sample_missing_wp_ids'] ?? null) !== []
            || ($verify['sample_extra_wp_ids'] ?? null) !== []
            || ($verify['type_mismatch'] ?? null) !== [];
        if ($hasVerifyGap) {
            return $base + [
                'allowed' => false,
                'reason' => 'primary_verify_dirty',
                'code' => 'primary_verify_dirty',
                'message' => "Chưa thể đồng bộ {$labelSecondary}. Hãy hoàn tất đồng bộ {$labelPrimary} trước.",
            ];
        }

        $scoring = is_array($meta['scoring'] ?? null) ? $meta['scoring'] : [];
        $total = (int) ($scoring['total'] ?? 0);
        $completed = (int) ($scoring['completed'] ?? 0);
        $unresolved = (int) ($scoring['unresolved'] ?? 0);
        $failed = (int) ($scoring['failed'] ?? 0);
        if ($total > 0 && ($completed < $total || $unresolved > 0)) {
            return $base + [
                'allowed' => false,
                'reason' => 'primary_scoring_incomplete',
                'code' => 'primary_scoring_incomplete',
                'message' => "Chưa thể đồng bộ {$labelSecondary}. Hãy hoàn tất đồng bộ {$labelPrimary} trước.",
            ];
        }
        // Blocking scoring failures (unresolved already checked); failed>0 with terminal = warnings OK
        // unless unresolved remains. Spec: primary blocking failures = 0 — treat unresolved as blocking.
        unset($failed);

        $freshness = $this->checkPrimaryFreshness($site, $primary);
        if (! ($freshness['current'] ?? false)) {
            return $base + [
                'allowed' => false,
                'reason' => 'primary_stale',
                'code' => 'primary_stale',
                'message' => "Ngôn ngữ chính đã có thay đổi. Đồng bộ {$labelPrimary} trước khi đồng bộ {$labelSecondary}.",
            ];
        }

        return $base + [
            'allowed' => true,
            'reason' => 'ok',
            'code' => 'ok',
            'message' => '',
        ];
    }

    /**
     * Cheap primary freshness vs last clean baseline site_revision.
     *
     * @return array{current: bool, baseline_revision: string|null, live_revision: string|null, message: string}
     */
    public function checkPrimaryFreshness(Site $site, ?string $primaryLanguage = null): array
    {
        $primary = $primaryLanguage ?? $this->languageScope->primaryLanguage($site);
        $primary = ArticleLanguageCode::normalize((string) $primary);
        $checkpoint = $this->checkpoints->get($site, $primary);
        $baselineRevision = trim((string) ($checkpoint['site_revision'] ?? ''));

        $discover = $this->client->discover($site, $primary !== '' ? ['language' => $primary] : []);
        if (! ($discover['success'] ?? false)) {
            // Fail closed: cannot prove freshness.
            return [
                'current' => false,
                'baseline_revision' => $baselineRevision !== '' ? $baselineRevision : null,
                'live_revision' => null,
                'message' => 'Không kiểm tra được trạng thái WordPress hiện tại.',
            ];
        }

        $payload = is_array($discover['discover'] ?? null) ? $discover['discover'] : [];
        $liveRevision = trim((string) ($payload['site_revision'] ?? ''));

        if ($baselineRevision === '' || $liveRevision === '') {
            // No revision to compare — treat as current if baseline exists.
            return [
                'current' => $this->checkpoints->hasSuccessfulBaseline($site, $primary),
                'baseline_revision' => $baselineRevision !== '' ? $baselineRevision : null,
                'live_revision' => $liveRevision !== '' ? $liveRevision : null,
                'message' => '',
            ];
        }

        return [
            'current' => hash_equals($baselineRevision, $liveRevision),
            'baseline_revision' => $baselineRevision,
            'live_revision' => $liveRevision,
            'message' => hash_equals($baselineRevision, $liveRevision)
                ? ''
                : 'Primary WordPress inventory changed since last clean baseline.',
        ];
    }

    /**
     * Translation availability from primary article wp_translation_map — no secondary import required.
     *
     * @return list<array{
     *   language: string,
     *   label: string,
     *   role: string,
     *   available_on_wp: int,
     *   synced_to_seo: int,
     *   sync_enabled: bool,
     *   gate_message: string
     * }>
     */
    public function translationCoverageSummary(Site $site): array
    {
        if (! $this->languageScope->isMultilingual($site)) {
            return [];
        }

        $primary = $this->languageScope->primaryLanguage($site) ?? 'vi';
        $options = $this->primaryLanguage->syncedLanguageOptions($site);
        $polylang = app(SitePolylangService::class);
        $rows = [];

        foreach ($options as $code => $label) {
            $code = ArticleLanguageCode::normalize((string) $code);
            if ($code === '') {
                continue;
            }
            $isPrimary = $code === $primary;
            $available = $isPrimary
                ? $this->countLocalLanguageArticles($site, $primary)
                : $this->countTranslationRefsOnPrimary($site, $primary, $code);
            $synced = $this->countLocalLanguageArticles($site, $code);

            $gate = $isPrimary
                ? ['allowed' => true, 'message' => '']
                : $this->evaluateSecondarySync($site, $code);

            $rows[] = [
                'language' => $code,
                'label' => $label !== '' ? $label : $polylang->languageLabel($code, $site),
                'flag' => $polylang->languageFlagEmoji($code),
                'role' => $isPrimary
                    ? SiteSyncV3Schema::LANGUAGE_ROLE_PRIMARY
                    : SiteSyncV3Schema::LANGUAGE_ROLE_SECONDARY,
                'available_on_wp' => $available,
                'synced_to_seo' => $synced,
                'sync_enabled' => (bool) ($gate['allowed'] ?? false),
                'gate_message' => (string) ($gate['message'] ?? ''),
                'gate_code' => (string) ($gate['code'] ?? ''),
            ];
        }

        return $rows;
    }

    private function countLocalLanguageArticles(Site $site, string $language): int
    {
        $language = ArticleLanguageCode::normalize($language);
        $query = ArticleContentClassification::scopeNonTerm(
            SeoArticle::query()
                ->where('site_id', (int) $site->id)
                ->hasWpPostId()
        );
        if ($language !== '') {
            $query->where('language', $language);
        }

        return (int) $query->count();
    }

    /**
     * Count primary articles that reference a secondary WP translation id.
     */
    private function countTranslationRefsOnPrimary(Site $site, string $primary, string $secondary): int
    {
        $primary = ArticleLanguageCode::normalize($primary);
        $secondary = ArticleLanguageCode::normalize($secondary);
        if ($secondary === '') {
            return 0;
        }

        $articles = ArticleContentClassification::scopeNonTerm(
            SeoArticle::query()
                ->where('site_id', (int) $site->id)
                ->hasWpPostId()
                ->where('language', $primary)
        )->with('articleMetas')->get();

        $count = 0;
        foreach ($articles as $article) {
            if (! $article instanceof SeoArticle) {
                continue;
            }
            $raw = trim((string) ($article->articleMetas
                ->firstWhere('meta_key', ArticlePolylangSyncService::META_TRANSLATION_MAP)?->meta_value ?? ''));
            if ($raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                continue;
            }
            $wpId = (int) ($decoded[$secondary] ?? 0);
            if ($wpId > 0) {
                $count++;
            }
        }

        return $count;
    }

    private function latestScopedRun(Site $site, string $language, string $role): ?SeoSiteSyncRun
    {
        $language = ArticleLanguageCode::normalize($language);
        $runs = SeoSiteSyncRun::query()
            ->where('site_id', (int) $site->id)
            ->where('protocol_version', (string) SiteSyncV3Schema::PROTOCOL)
            ->orderByDesc('id')
            ->limit(40)
            ->get();

        foreach ($runs as $run) {
            $meta = is_array($run->meta) ? $run->meta : [];
            $runLang = ArticleLanguageCode::normalize((string) ($meta[SiteSyncV3Schema::META_LANGUAGE_SCOPE] ?? ''));
            $runRole = strtolower(trim((string) ($meta[SiteSyncV3Schema::META_LANGUAGE_ROLE] ?? SiteSyncV3Schema::LANGUAGE_ROLE_PRIMARY)));
            if ($language !== '' && $runLang !== '' && $runLang !== $language) {
                continue;
            }
            if ($runRole !== $role && $runLang !== '') {
                continue;
            }

            return $run;
        }

        return null;
    }
}
