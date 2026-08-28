<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Enums\ArticleIndexCheckStatus;
use Omnichannel\Addons\Seo\Services\IndexHealth\ArticleIndexCanonicalUrlResolver;
use Omnichannel\Addons\Seo\Services\IndexHealth\ArticleIndexHealthEligibility;
use Omnichannel\Addons\Seo\Services\IndexHealth\ArticleIndexHealthRecorder;
use RuntimeException;
use Throwable;

/**
 * Single-article GSC URL Inspection → canonical Index Health recorder.
 * API failures do NOT mutate Index Health.
 */
final class GscUrlInspectionService
{
    public function __construct(
        private readonly GscUrlInspectionClient $client = new GscUrlInspectionClient,
        private readonly GscUrlInspectionBindingResolver $bindings = new GscUrlInspectionBindingResolver,
        private readonly GscUrlInspectionHealthMapper $mapper = new GscUrlInspectionHealthMapper,
        private readonly GscUrlInspectionLockService $locks = new GscUrlInspectionLockService,
        private readonly ArticleIndexHealthEligibility $eligibility = new ArticleIndexHealthEligibility,
        private readonly ArticleIndexCanonicalUrlResolver $urls = new ArticleIndexCanonicalUrlResolver,
        private readonly ArticleIndexHealthRecorder $recorder = new ArticleIndexHealthRecorder,
    ) {}

    /**
     * @param  array{url?: string, require_observed_publish?: bool}|null  $options
     * @return array{
     *   ok: bool,
     *   queued: bool,
     *   article_id: int,
     *   site_id: int,
     *   url: string|null,
     *   property_uri: string|null,
     *   check_status: string|null,
     *   effective_health: string|null,
     *   check_id: int|null,
     *   source: string,
     *   diagnostics: array<string, mixed>|null,
     *   error_code: string|null,
     *   error_message: string|null,
     *   transitioned_to_dropped: bool,
     *   recovered_from_dropped: bool,
     * }
     */
    public function inspectArticle(int $articleId, ?int $actorId = null, ?array $options = null): array
    {
        $article = SeoArticle::query()->with(['wordpressLink', 'articleMetas'])->find($articleId);
        if (! $article instanceof SeoArticle) {
            return $this->failure($articleId, 0, 'article.not_found', 'Article not found.');
        }

        $siteId = (int) ($article->site_id ?? 0);
        if ($siteId <= 0) {
            return $this->failure($articleId, 0, 'article.invalid_site', 'Article has no site.');
        }

        try {
            return $this->locks->withArticleLock($siteId, $articleId, function () use ($article, $articleId, $siteId, $actorId, $options): array {
                return $this->inspectUnlocked($article, $articleId, $siteId, $actorId, $options);
            }, 0);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'operation.locked')) {
                return $this->failure(
                    $articleId,
                    $siteId,
                    'gsc.inspection_locked',
                    'URL Inspection already in progress for this article.',
                );
            }

            return $this->failure($articleId, $siteId, 'gsc.failed', $e->getMessage());
        }
    }

    /**
     * Inspect a pre-resolved public URL for a site.
     * Records Index Health only when the Article still exists; otherwise returns GSC verdict only.
     *
     * @return array<string, mixed>
     */
    public function inspectResolvedUrl(int $siteId, string $url, int $articleId = 0, ?int $actorId = null): array
    {
        $url = trim($url);
        if ($siteId <= 0) {
            return $this->failure($articleId, 0, 'article.invalid_site', 'Site is required.');
        }
        if ($url === '' || ! $this->urls->isPublicHttpUrl($url)) {
            return $this->failure($articleId, $siteId, 'article.no_url', 'Canonical public URL is required.');
        }

        $article = null;
        if ($articleId > 0) {
            $found = SeoArticle::query()->with(['wordpressLink', 'articleMetas'])->find($articleId);
            if ($found instanceof SeoArticle && (int) ($found->site_id ?? 0) === $siteId) {
                $article = $found;
            }
        }

        if ($article instanceof SeoArticle) {
            try {
                return $this->locks->withArticleLock($siteId, $articleId, function () use ($article, $articleId, $siteId, $actorId, $url): array {
                    return $this->inspectUnlocked($article, $articleId, $siteId, $actorId, [
                        'url' => $url,
                        'require_observed_publish' => false,
                    ]);
                }, 0);
            } catch (RuntimeException $e) {
                if (str_contains($e->getMessage(), 'operation.locked')) {
                    return $this->failure(
                        $articleId,
                        $siteId,
                        'gsc.inspection_locked',
                        'URL Inspection already in progress for this article.',
                    );
                }

                return $this->failure($articleId, $siteId, 'gsc.failed', $e->getMessage());
            }
        }

        // Orphan archive URL — GSC inspect without Index Health recorder.
        return $this->inspectUrlOnly($siteId, $url, $articleId);
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectUrlOnly(int $siteId, string $url, int $articleId): array
    {
        try {
            $binding = $this->bindings->resolveForSite($siteId);
        } catch (GscUrlInspectionApiException $e) {
            return $this->failure($articleId, $siteId, $e->errorCode, $e->getMessage(), null, $url);
        }

        $propertyUri = (string) $binding['property_uri'];

        try {
            $inspection = $this->client->inspect($url, $propertyUri, $binding['connection']);
        } catch (GscUrlInspectionApiException $e) {
            return $this->failure(
                $articleId,
                $siteId,
                $e->errorCode,
                $e->getMessage(),
                $propertyUri,
                $url,
                rateLimited: $e->rateLimited,
            );
        } catch (Throwable $e) {
            return $this->failure(
                $articleId,
                $siteId,
                'gsc.transient_error',
                mb_substr(trim($e->getMessage()), 0, 240) ?: 'GSC URL Inspection failed.',
                $propertyUri,
                $url,
            );
        }

        $checkStatus = $this->mapper->map($inspection);

        return [
            'ok' => true,
            'queued' => false,
            'article_id' => $articleId,
            'site_id' => $siteId,
            'url' => $url,
            'property_uri' => $propertyUri,
            'check_status' => $checkStatus->value,
            'effective_health' => $checkStatus->value,
            'check_id' => null,
            'source' => GscUrlInspectionPolicy::sourceKey(),
            'diagnostics' => $inspection->diagnostics(),
            'error_code' => null,
            'error_message' => null,
            'transitioned_to_dropped' => false,
            'recovered_from_dropped' => false,
            'rate_limited' => false,
        ];
    }

    /**
     * @param  array{url?: string, require_observed_publish?: bool}|null  $options
     * @return array<string, mixed>
     */
    private function inspectUnlocked(
        SeoArticle $article,
        int $articleId,
        int $siteId,
        ?int $actorId,
        ?array $options = null,
    ): array {
        $requireObservedPublish = (bool) ($options['require_observed_publish'] ?? true);
        $urlOverride = isset($options['url']) ? trim((string) $options['url']) : '';

        if ($requireObservedPublish && ! $this->eligibility->isEligible($article)) {
            return $this->failure(
                $articleId,
                $siteId,
                'article.not_eligible',
                'Article is not eligible for Index Health (must be observed WP publish with public URL).',
            );
        }

        $url = $urlOverride !== '' && $this->urls->isPublicHttpUrl($urlOverride)
            ? $urlOverride
            : $this->urls->resolve($article);
        if ($url === null || $url === '') {
            return $this->failure($articleId, $siteId, 'article.no_url', 'Canonical public URL is required.');
        }

        try {
            $binding = $this->bindings->resolveForSite($siteId);
        } catch (GscUrlInspectionApiException $e) {
            return $this->failure($articleId, $siteId, $e->errorCode, $e->getMessage(), null, $url);
        }

        $propertyUri = (string) $binding['property_uri'];

        try {
            $inspection = $this->client->inspect($url, $propertyUri, $binding['connection']);
        } catch (GscUrlInspectionApiException $e) {
            return $this->failure(
                $articleId,
                $siteId,
                $e->errorCode,
                $e->getMessage(),
                $propertyUri,
                $url,
                rateLimited: $e->rateLimited,
            );
        } catch (Throwable $e) {
            return $this->failure(
                $articleId,
                $siteId,
                'gsc.transient_error',
                mb_substr(trim($e->getMessage()), 0, 240) ?: 'GSC URL Inspection failed.',
                $propertyUri,
                $url,
            );
        }

        $checkStatus = $this->mapper->map($inspection);
        $diagnostics = $inspection->diagnostics();

        try {
            $recorded = $this->recorder->record(
                $article,
                $checkStatus,
                GscUrlInspectionPolicy::sourceKey(),
                $actorId,
                null,
                null,
                $requireObservedPublish,
                $diagnostics,
                $url,
            );
        } catch (Throwable $e) {
            return $this->failure(
                $articleId,
                $siteId,
                'index_health.record_failed',
                mb_substr(trim($e->getMessage()), 0, 240),
                $propertyUri,
                $url,
            );
        }

        return [
            'ok' => true,
            'queued' => false,
            'article_id' => $articleId,
            'site_id' => $siteId,
            'url' => (string) ($recorded['url'] ?? $url),
            'property_uri' => $propertyUri,
            'check_status' => (string) ($recorded['status'] ?? $checkStatus->value),
            'effective_health' => (string) ($recorded['effective_health'] ?? ''),
            'check_id' => (int) ($recorded['check_id'] ?? 0) ?: null,
            'source' => GscUrlInspectionPolicy::sourceKey(),
            'diagnostics' => $diagnostics,
            'error_code' => null,
            'error_message' => null,
            'transitioned_to_dropped' => (bool) ($recorded['transitioned_to_dropped'] ?? false),
            'recovered_from_dropped' => (bool) ($recorded['recovered_from_dropped'] ?? false),
            'rate_limited' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(
        int $articleId,
        int $siteId,
        string $errorCode,
        string $message,
        ?string $propertyUri = null,
        ?string $url = null,
        bool $rateLimited = false,
    ): array {
        return [
            'ok' => false,
            'queued' => false,
            'article_id' => $articleId,
            'site_id' => $siteId,
            'url' => $url,
            'property_uri' => $propertyUri,
            'check_status' => null,
            'effective_health' => null,
            'check_id' => null,
            'source' => GscUrlInspectionPolicy::sourceKey(),
            'diagnostics' => null,
            'error_code' => $errorCode,
            'error_message' => $message,
            'transitioned_to_dropped' => false,
            'recovered_from_dropped' => false,
            'rate_limited' => $rateLimited,
        ];
    }
}
