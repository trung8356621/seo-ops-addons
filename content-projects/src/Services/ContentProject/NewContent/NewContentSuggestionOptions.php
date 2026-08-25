<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent;

/**
 * Create-new planner options. Quantity is the primary user control.
 *
 * @phpstan-type Options array{
 *   quantity: int,
 *   direction: string,
 *   focus: string,
 *   post_type: string,
 *   taxonomy: string,
 *   use_site_context: bool,
 *   use_keyword_intelligence: bool,
 *   use_mcp_context: bool
 * }
 */
final class NewContentSuggestionOptions
{
    public const DIRECTION_AUTOMATIC = 'automatic';

    public const DIRECTION_SEASONAL = 'seasonal';

    public const DIRECTION_EVERGREEN = 'evergreen';

    public const POST_TYPE_ARTICLE = 'article';

    public const MIN_QUANTITY = 1;

    public const MAX_QUANTITY = 100;

    /**
     * @param  array<string, mixed>  $input
     * @return Options
     */
    public static function normalize(array $input): array
    {
        $quantity = (int) ($input['quantity'] ?? $input['requested_quantity'] ?? 20);
        $direction = strtolower(trim((string) ($input['direction'] ?? self::DIRECTION_AUTOMATIC)));
        if (! in_array($direction, [self::DIRECTION_AUTOMATIC, self::DIRECTION_SEASONAL, self::DIRECTION_EVERGREEN], true)) {
            $direction = self::DIRECTION_AUTOMATIC;
        }

        $postType = strtolower(trim((string) ($input['post_type'] ?? self::POST_TYPE_ARTICLE)));
        if ($postType === 'post') {
            $postType = self::POST_TYPE_ARTICLE;
        }
        if ($postType === '' || $postType === 'page') {
            $postType = self::POST_TYPE_ARTICLE;
        }

        return [
            'quantity' => max(self::MIN_QUANTITY, min(self::MAX_QUANTITY, $quantity)),
            'direction' => $direction,
            'focus' => trim((string) ($input['focus'] ?? '')),
            'post_type' => $postType,
            'taxonomy' => trim((string) ($input['taxonomy'] ?? '')),
            'use_site_context' => (bool) ($input['use_site_context'] ?? true),
            'use_keyword_intelligence' => (bool) ($input['use_keyword_intelligence'] ?? true),
            'use_mcp_context' => (bool) ($input['use_mcp_context'] ?? true),
        ];
    }

    /**
     * @param  Options|array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function snapshot(array $options, string $primaryLanguage): array
    {
        $normalized = self::normalize($options);

        return [
            'quantity' => $normalized['quantity'],
            'direction' => $normalized['direction'],
            'focus' => $normalized['focus'],
            'post_type' => $normalized['post_type'],
            'taxonomy' => $normalized['taxonomy'],
            'primary_language' => $primaryLanguage,
            'use_site_context' => $normalized['use_site_context'],
            'use_keyword_intelligence' => $normalized['use_keyword_intelligence'],
            'use_mcp_context' => $normalized['use_mcp_context'],
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return Options
     */
    public static function fromSnapshot(array $snapshot): array
    {
        return self::normalize($snapshot);
    }
}
