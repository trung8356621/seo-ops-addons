<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductReview;

/**
 * Canonical review identity fingerprint shared by Laravel persistence and WP exists().
 *
 * Hash = sha256( lower(author) + "\0" + lower(content) + "\0" + rating )
 */
final class ProductReviewContentFingerprint
{
    public static function hash(string $author, string $content, int|string|null $rating): string
    {
        return hash(
            'sha256',
            mb_strtolower(trim($author))."\0".mb_strtolower(trim($content))."\0".(string) $rating,
        );
    }

    /**
     * @param  array<string, mixed>  $remoteItem
     */
    public static function fromRemoteItem(array $remoteItem): string
    {
        $author = (string) ($remoteItem['author'] ?? $remoteItem['author_name'] ?? '');
        $content = (string) ($remoteItem['content'] ?? $remoteItem['comment'] ?? '');
        $rating = isset($remoteItem['rating']) && is_numeric($remoteItem['rating'])
            ? (int) $remoteItem['rating']
            : null;

        return self::hash($author, $content, $rating);
    }
}
