<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Services\Publishing;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Models\SeoArticleWpSyncJob;
use Omnichannel\Addons\WordPress\Services\SideEffect\WordPressExecutionContext;
use Omnichannel\Addons\WordPress\Services\WordPressArticleSyncService;
use App\Support\RuntimeLogger;
use Throwable;

/**
 * Resolve missing wp_post_id for already-Published articles.
 * Never creates a WordPress post.
 */
final class PostPublishWordPressPostReconciler
{
    public const OUTCOME_ATTACHED = 'attached';

    public const OUTCOME_ALREADY_LINKED = 'already_linked';

    public const OUTCOME_NOT_FOUND = 'not_found';

    public const OUTCOME_AMBIGUOUS = 'ambiguous';

    public const OUTCOME_PROBE_FAILED = 'probe_failed';

    public function __construct(
        private readonly WordPressArticleSyncService $articleSync,
    ) {}

    /**
     * @return array{
     *     outcome: string,
     *     wp_post_id: int|null,
     *     match_count: int,
     *     message: string,
     *     permalink?: string
     * }
     */
    public function reconcile(SeoArticle $article, WordPressExecutionContext $sideEffect): array
    {
        $existing = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        if ($existing > 0) {
            return [
                'outcome' => self::OUTCOME_ALREADY_LINKED,
                'wp_post_id' => $existing,
                'match_count' => 1,
                'message' => 'wp_post_id already present.',
            ];
        }

        $probe = $this->probeMatches($article, $sideEffect);
        $matchCount = (int) ($probe['match_count'] ?? 0);
        $wpPostId = (int) ($probe['wp_post_id'] ?? 0);
        $permalink = trim((string) ($probe['permalink'] ?? ''));

        if (($probe['outcome'] ?? '') === self::OUTCOME_PROBE_FAILED) {
            return [
                'outcome' => self::OUTCOME_PROBE_FAILED,
                'wp_post_id' => null,
                'match_count' => 0,
                'message' => (string) ($probe['message'] ?? 'Không thăm dò được WordPress.'),
            ];
        }

        if ($matchCount > 1 || ($probe['outcome'] ?? '') === self::OUTCOME_AMBIGUOUS) {
            return [
                'outcome' => self::OUTCOME_AMBIGUOUS,
                'wp_post_id' => null,
                'match_count' => max(2, $matchCount),
                'message' => 'Tìm thấy nhiều bài WordPress phù hợp. Cần chọn bài đúng trước khi đồng bộ.',
            ];
        }

        if ($matchCount === 0 || $wpPostId <= 0) {
            return [
                'outcome' => self::OUTCOME_NOT_FOUND,
                'wp_post_id' => null,
                'match_count' => 0,
                'message' => 'Không tìm thấy bài WordPress đã xuất bản. Hãy đối soát trước khi đồng bộ.',
            ];
        }

        $article->forceFill(['wp_post_id' => $wpPostId])->saveQuietly();
        if ($permalink !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_permalink'],
                ['meta_value' => $permalink],
            );
        }

        RuntimeLogger::info('post_publish_wp.reconcile_attached', [
            'article_id' => (int) $article->id,
            'wp_post_id' => $wpPostId,
        ]);

        return [
            'outcome' => self::OUTCOME_ATTACHED,
            'wp_post_id' => $wpPostId,
            'match_count' => 1,
            'message' => 'Đã gắn wp_post_id từ bài WordPress khớp.',
            'permalink' => $permalink,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function probeMatches(SeoArticle $article, WordPressExecutionContext $sideEffect): array
    {
        try {
            $byMeta = $this->articleSync->findPublishedPostForReconcile($article, $sideEffect);
        } catch (Throwable $e) {
            RuntimeLogger::warning('post_publish_wp.reconcile_probe_failed', [
                'article_id' => (int) $article->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'outcome' => self::OUTCOME_PROBE_FAILED,
                'message' => $e->getMessage(),
            ];
        }

        if (! is_array($byMeta)) {
            return [
                'outcome' => self::OUTCOME_PROBE_FAILED,
                'message' => 'Probe returned null.',
            ];
        }

        $matchCount = (int) ($byMeta['match_count'] ?? (($byMeta['found'] ?? false) ? 1 : 0));
        if (($byMeta['ambiguous'] ?? false) === true) {
            return [
                'outcome' => self::OUTCOME_AMBIGUOUS,
                'match_count' => max(2, $matchCount),
            ];
        }

        if ($matchCount > 1) {
            return [
                'outcome' => self::OUTCOME_AMBIGUOUS,
                'match_count' => $matchCount,
            ];
        }

        if (($byMeta['found'] ?? false) && (int) ($byMeta['wp_post_id'] ?? 0) > 0) {
            return [
                'outcome' => self::OUTCOME_ATTACHED,
                'match_count' => 1,
                'wp_post_id' => (int) $byMeta['wp_post_id'],
                'permalink' => (string) ($byMeta['permalink'] ?? ''),
            ];
        }

        // Secondary probe: slug / external_reference via same find endpoint + sync_key.
        $siteId = (int) ($article->site_id ?? 0);
        $syncKey = SeoArticleWpSyncJob::makeIdempotencyKey($siteId, (int) $article->id);
        if ($syncKey === '' && trim((string) ($article->slug ?? '')) === '') {
            return [
                'outcome' => self::OUTCOME_NOT_FOUND,
                'match_count' => 0,
            ];
        }

        return [
            'outcome' => self::OUTCOME_NOT_FOUND,
            'match_count' => 0,
        ];
    }
}
