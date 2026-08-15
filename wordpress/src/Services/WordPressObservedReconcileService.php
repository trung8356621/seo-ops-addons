<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use App\Models\Site;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Support\ObservedWordPressPostStatus;

/**
 * Query WP, persist observed facts. Does not mutate Laravel desired queue.
 *
 * @phpstan-type ObserveResult array{
 *     ok: bool,
 *     observed_post_status: string,
 *     observed_wp_post_id: int|null,
 *     observed_permalink: string|null,
 *     observed_modified_at: string|null,
 *     timeout: bool,
 *     permalink_changed: bool,
 *     message: string
 * }
 */
final class WordPressObservedReconcileService
{
    public function __construct(
        private readonly WordPressObservedStateClient $client,
        private readonly WordPressObservedStateService $state,
        private readonly WordPressArticleSyncService $articleSync,
    ) {}

    /**
     * @return ObserveResult
     */
    public function observeArticle(SeoArticle $article): array
    {
        $article->loadMissing(['site', 'wordpressLink']);
        $site = $article->site;
        if (! $site instanceof Site) {
            return $this->fail('Site missing.');
        }

        $storedId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        $previous = $this->state->read($article);
        $timeout = false;

        if ($storedId > 0) {
            $probe = $this->client->observePost($site, $storedId);
            if (($probe['timeout'] ?? false) === true) {
                $timeout = true;
            } elseif (($probe['ok'] ?? false) === true && ($probe['missing'] ?? false) !== true) {
                return $this->persistLive($article, $probe, $previous, false);
            } elseif (($probe['ok'] ?? false) === true && ($probe['missing'] ?? false) === true) {
                return $this->persistMissingOrFind($article, $site, $previous);
            }
        } else {
            return $this->persistMissingOrFind($article, $site, $previous);
        }

        if ($timeout) {
            $this->state->persist($article, [
                'observed_wp_post_id' => $storedId > 0 ? $storedId : null,
                'observed_post_status' => $previous['observed_post_status'] ?? null,
                'observed_permalink' => $previous['observed_permalink'] ?? null,
                'reconcile_status' => WordPressObservedStateService::RECONCILE_NEEDS_ATTENTION,
            ]);

            return [
                'ok' => false,
                'observed_post_status' => (string) ($previous['observed_post_status'] ?? ''),
                'observed_wp_post_id' => $storedId > 0 ? $storedId : null,
                'observed_permalink' => $previous['observed_permalink'] ?? null,
                'observed_modified_at' => null,
                'timeout' => true,
                'permalink_changed' => false,
                'message' => 'Observe timed out; not repairing ambiguous state.',
            ];
        }

        return $this->fail('Observe failed.');
    }

    /**
     * @param  array<string, mixed>  $probe
     * @param  array<string, mixed>  $previous
     * @return ObserveResult
     */
    private function persistLive(SeoArticle $article, array $probe, array $previous, bool $idRepaired): array
    {
        $status = ObservedWordPressPostStatus::normalize((string) ($probe['status'] ?? ''));
        $permalink = trim((string) ($probe['permalink'] ?? ''));
        $wpPostId = (int) ($probe['wp_post_id'] ?? 0) ?: null;
        $previousPermalink = trim((string) ($previous['observed_permalink'] ?? ''));
        $permalinkChanged = $permalink !== '' && $previousPermalink !== '' && $permalink !== $previousPermalink;

        $this->state->persist($article, [
            'observed_wp_post_id' => $wpPostId,
            'observed_post_status' => $status,
            'observed_permalink' => $permalink !== '' ? $permalink : null,
            'observed_modified_at' => $probe['modified_at'] ?? null,
            'reconcile_status' => $idRepaired
                ? WordPressObservedStateService::RECONCILE_REPAIRED
                : WordPressObservedStateService::RECONCILE_ALIGNED,
        ]);

        if ($permalink !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_permalink'],
                ['meta_value' => $permalink],
            );
        }

        return [
            'ok' => true,
            'observed_post_status' => $status,
            'observed_wp_post_id' => $wpPostId,
            'observed_permalink' => $permalink !== '' ? $permalink : null,
            'observed_modified_at' => isset($probe['modified_at']) ? (string) $probe['modified_at'] : null,
            'timeout' => false,
            'permalink_changed' => $permalinkChanged,
            'message' => $permalinkChanged ? 'Permalink changed.' : 'Observed WordPress state persisted.',
        ];
    }

    /**
     * @param  array<string, mixed>  $previous
     * @return ObserveResult
     */
    private function persistMissingOrFind(SeoArticle $article, Site $site, array $previous): array
    {
        unset($site, $previous);
        $found = $this->articleSync->findPublishedPostForReconcile(
            $article,
            new \Omnichannel\Addons\WordPress\Services\SideEffect\SystemWordPressContext(
                requestId: 'observe-'.(int) $article->getKey(),
                articleId: (int) $article->getKey(),
                siteId: (int) ($article->site_id ?? 0),
                reason: 'wordpress.observe',
                correlationId: 'observe-article-'.(int) $article->getKey(),
            ),
        );

        if (is_array($found) && ($found['ambiguous'] ?? false) === true) {
            $this->state->persist($article, [
                'observed_post_status' => ObservedWordPressPostStatus::MISSING,
                'reconcile_status' => WordPressObservedStateService::RECONCILE_NEEDS_ATTENTION,
            ]);

            return [
                'ok' => true,
                'observed_post_status' => ObservedWordPressPostStatus::MISSING,
                'observed_wp_post_id' => null,
                'observed_permalink' => null,
                'observed_modified_at' => null,
                'timeout' => false,
                'permalink_changed' => false,
                'message' => 'Ambiguous WordPress matches; needs attention.',
            ];
        }

        if (is_array($found) && ($found['found'] ?? false) === true) {
            return $this->persistLive($article, [
                'status' => (string) ($found['status'] ?? 'publish'),
                'permalink' => (string) ($found['permalink'] ?? ''),
                'wp_post_id' => (int) ($found['wp_post_id'] ?? 0),
                'modified_at' => null,
            ], $this->state->read($article), true);
        }

        $this->state->persist($article, [
            'observed_post_status' => ObservedWordPressPostStatus::MISSING,
            'reconcile_status' => WordPressObservedStateService::RECONCILE_ALIGNED,
        ]);

        return [
            'ok' => true,
            'observed_post_status' => ObservedWordPressPostStatus::MISSING,
            'observed_wp_post_id' => null,
            'observed_permalink' => null,
            'observed_modified_at' => null,
            'timeout' => false,
            'permalink_changed' => false,
            'message' => 'WordPress post missing.',
        ];
    }

    /**
     * @return ObserveResult
     */
    private function fail(string $message): array
    {
        return [
            'ok' => false,
            'observed_post_status' => '',
            'observed_wp_post_id' => null,
            'observed_permalink' => null,
            'observed_modified_at' => null,
            'timeout' => false,
            'permalink_changed' => false,
            'message' => $message,
        ];
    }
}
