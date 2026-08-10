<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductReview\Data;

/**
 * Normalized WordPress product review for Edit Article UI.
 *
 * @phpstan-type ReviewRow array{
 *     id: string|int|null,
 *     author: string,
 *     author_email?: string|null,
 *     content: string,
 *     date: string|null,
 *     rating: int|null,
 *     wp_comment_id: int|null,
 *     source: string,
 *     remote: bool
 * }
 */
final class ProductReviewDto
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string|int|null $id,
        public readonly string $author,
        public readonly ?string $authorEmail,
        public readonly string $content,
        public readonly ?string $date,
        public readonly ?int $rating,
        public readonly ?int $wpCommentId,
        public readonly string $source,
        public readonly bool $remote,
        public readonly array $raw = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $row = [
            'id' => $this->id,
            'author' => $this->author,
            'content' => $this->content,
            'date' => $this->date,
            'rating' => $this->rating,
            'wp_comment_id' => $this->wpCommentId,
            'source' => $this->source,
            'remote' => $this->remote,
            'generated' => ($this->raw['generated'] ?? false) === true
                || in_array($this->source, ['seo_content_ai', 'laravel'], true)
                || (int) ($this->raw['_omi_review_id'] ?? $this->raw['laravel_review_id'] ?? 0) > 0,
            'status' => $this->remote ? 'reviewed' : 'pending',
        ];

        if ($this->authorEmail !== null && $this->authorEmail !== '') {
            $row['author_email'] = $this->authorEmail;
        }

        return $row;
    }
}
