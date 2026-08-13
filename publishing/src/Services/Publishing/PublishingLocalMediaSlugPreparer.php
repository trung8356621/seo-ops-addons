<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Services\Publishing;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoMediaArticleSlugFixAllService;
use Omnichannel\Addons\WordPress\Services\WordPressWriteReadinessGuard;
use App\Support\RuntimeLogger;
use Throwable;

/**
 * Pre-publish local media slug preparation for scheduled / queue publishing.
 *
 * Auto-fixes deterministic Laravel-local placeholder slugs via the same
 * SeoMediaArticleSlugFixAllService / SeoMediaArticleSlugFixService path as editor
 * "Fix slug all". Non-auto-fixable leftovers become hard publish blocks.
 */
final class PublishingLocalMediaSlugPreparer
{
    public const OUTCOME_READY = 'ready';

    public const OUTCOME_PREPARED = 'prepared';

    public const OUTCOME_HARD_BLOCKED = 'hard_blocked';

    public function __construct(
        private readonly WordPressWriteReadinessGuard $readiness,
        private readonly SeoMediaArticleSlugFixAllService $slugFixAll,
    ) {}

    /**
     * @return array{
     *     outcome: string,
     *     ready: bool,
     *     message: string,
     *     pending_before: list<int>,
     *     pending_after: list<int>,
     *     applied: int,
     *     not_auto_fixable_ids: list<int>,
     *     auto_fix_attempted: bool
     * }
     */
    public function prepareForPublish(SeoArticle $article): array
    {
        $article = $article->fresh() ?? $article;
        $pendingBefore = $this->readiness->pendingLocalSlugFixIds($article);
        if ($pendingBefore === []) {
            return [
                'outcome' => self::OUTCOME_READY,
                'ready' => true,
                'message' => 'Media slug preflight clear.',
                'pending_before' => [],
                'pending_after' => [],
                'applied' => 0,
                'not_auto_fixable_ids' => [],
                'auto_fix_attempted' => false,
            ];
        }

        $autoFixable = [];
        $notAutoFixable = [];
        foreach ($pendingBefore as $mediaId) {
            $media = \Omnichannel\Addons\Media\Models\SeoMedia::query()->find($mediaId);
            if ($media instanceof \Omnichannel\Addons\Media\Models\SeoMedia
                && $this->readiness->isAutoFixableLocalMedia($media)
            ) {
                $autoFixable[] = $mediaId;
            } else {
                $notAutoFixable[] = $mediaId;
            }
        }

        $applied = 0;
        $fixMessage = '';
        $autoFixAttempted = $autoFixable !== [];

        if ($autoFixAttempted) {
            try {
                $fix = $this->slugFixAll->fixPendingMediaForPublish($article, $autoFixable);
                $applied = (int) ($fix['applied'] ?? 0);
                $fixMessage = (string) ($fix['message'] ?? '');
                foreach (is_array($fix['not_auto_fixable_ids'] ?? null) ? $fix['not_auto_fixable_ids'] : [] as $id) {
                    $id = (int) $id;
                    if ($id > 0) {
                        $notAutoFixable[] = $id;
                    }
                }
                RuntimeLogger::info('publishing.media_slug_autofix', [
                    'article_id' => (int) $article->getKey(),
                    'pending_before' => $pendingBefore,
                    'auto_fixable' => $autoFixable,
                    'applied' => $applied,
                    'success' => (bool) ($fix['success'] ?? false),
                    'message' => $fixMessage,
                ]);
            } catch (Throwable $e) {
                RuntimeLogger::warning('publishing.media_slug_autofix_failed', [
                    'article_id' => (int) $article->getKey(),
                    'pending_before' => $pendingBefore,
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ]);
                $fixMessage = 'Auto-fix slug media thất bại: '.$e->getMessage();
                $notAutoFixable = array_values(array_unique(array_merge($notAutoFixable, $autoFixable)));
            }
        }

        $article = $article->fresh() ?? $article;
        $pendingAfter = $this->readiness->pendingLocalSlugFixIds($article);
        if ($pendingAfter === []) {
            return [
                'outcome' => $applied > 0 ? self::OUTCOME_PREPARED : self::OUTCOME_READY,
                'ready' => true,
                'message' => $applied > 0
                    ? ($fixMessage !== '' ? $fixMessage : 'Đã chuẩn hóa slug media local trước khi xuất bản.')
                    : 'Media slug preflight clear.',
                'pending_before' => $pendingBefore,
                'pending_after' => [],
                'applied' => $applied,
                'not_auto_fixable_ids' => array_values(array_unique($notAutoFixable)),
                'auto_fix_attempted' => $autoFixAttempted,
            ];
        }

        $ids = array_values(array_unique(array_merge($pendingAfter, $notAutoFixable)));
        $label = implode(', ', array_map(static fn (int $id): string => (string) $id, array_slice($ids, 0, 10)));
        $message = 'Media '.$label.' cần xử lý trước khi xuất bản'
            .($fixMessage !== '' ? ' ('.$fixMessage.')' : '');

        return [
            'outcome' => self::OUTCOME_HARD_BLOCKED,
            'ready' => false,
            'message' => $message,
            'pending_before' => $pendingBefore,
            'pending_after' => $pendingAfter,
            'applied' => $applied,
            'not_auto_fixable_ids' => array_values(array_unique($notAutoFixable)),
            'auto_fix_attempted' => $autoFixAttempted,
        ];
    }
}
