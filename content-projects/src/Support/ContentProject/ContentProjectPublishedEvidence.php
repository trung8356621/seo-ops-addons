<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\WordPress\Support\ObservedWordPressPostStatus;

/**
 * Canonical "Workflow = Published" evidence.
 *
 * Observed WordPress live status wins when present.
 * Publisher success stamp is fallback only while the item is still in the
 * Publishing Queue. Queue history alone is never enough.
 */
final class ContentProjectPublishedEvidence
{
    /**
     * @param  array<string, mixed>  $hints  observed_post_status?: string|null
     */
    public static function fromTaskAndArticle(
        SeoProjectTask $task,
        ?SeoArticle $article = null,
        array $hints = [],
    ): bool {
        return self::fromRow([
            'observed_post_status' => self::resolveObservedPostStatus($article, $hints),
            'publish_published_at' => $task->getAttributes()['publish_published_at'] ?? null,
            'queue_status' => $task->getAttributes()['publish_queue_status'] ?? 'none',
            'publishing_queued_at' => $task->getAttributes()['publishing_queued_at'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row): bool
    {
        $observed = strtolower(trim((string) ($row['observed_post_status'] ?? '')));
        if ($observed !== '') {
            return ObservedWordPressPostStatus::isLiveOnSite($observed);
        }

        $stamp = $row['publish_published_at'] ?? null;
        if ($stamp === null || $stamp === '') {
            return false;
        }

        $stillInQueue = ! empty($row['publishing_queued_at']) || ! empty($row['in_publishing_queue']);

        // Stamp is publisher-runtime fallback only while the item is still queued.
        // Leftover publish_queue_status / stamp after Return is not WordPress evidence.
        if (! $stillInQueue) {
            return false;
        }

        return true;
    }

    /**
     * Observed WP status wins. Stamp-alone (no queue context) is not Published.
     * Callers with queue membership must use fromRow() / fromTaskAndArticle().
     */
    public static function fromObservedAndStamp(mixed $observedPostStatus, mixed $publishPublishedAt): bool
    {
        return self::fromRow([
            'observed_post_status' => $observedPostStatus,
            'publish_published_at' => $publishPublishedAt,
            'queue_status' => 'none',
            'publishing_queued_at' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $hints
     */
    public static function resolveObservedPostStatus(?SeoArticle $article, array $hints = []): ?string
    {
        if (array_key_exists('observed_post_status', $hints) && $hints['observed_post_status'] !== null) {
            $hint = strtolower(trim((string) $hints['observed_post_status']));

            return $hint !== '' ? $hint : null;
        }

        if (! $article instanceof SeoArticle || ! $article->relationLoaded('wordpressLink')) {
            return null;
        }

        $link = $article->getRelation('wordpressLink');
        if (! is_object($link)) {
            return null;
        }

        $raw = strtolower(trim((string) ($link->observed_post_status ?? '')));

        return $raw !== '' ? $raw : null;
    }
}
