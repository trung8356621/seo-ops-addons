<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\AuditNotes\AuditNoteDnaNormalizer;

/**
 * Create-new planner options. Quantity + optional notes + content type (post|product)
 * + structured SEO Audit Note items (Cluster DNA snapshots).
 *
 * @phpstan-type Options array{
 *   quantity: int,
 *   direction: string,
 *   focus: string,
 *   notes: string,
 *   note_items: list<array{
 *     cluster_ref: string,
 *     cluster_name_snapshot: string,
 *     mcp_share_snapshot: float,
 *     dna: list<array{phrase: string, weight: int, source: string}>
 *   }>,
 *   post_type: string,
 *   content_type: string,
 *   taxonomy: string,
 *   use_site_context: bool,
 *   use_keyword_intelligence: bool,
 *   use_mcp_context: bool
 * }
 */
final class NewContentSuggestionOptions
{
    public const DIRECTION_AUTOMATIC = 'automatic';

    /** @deprecated Kept for historical snapshots / Agent callers only. */
    public const DIRECTION_SEASONAL = 'seasonal';

    /** @deprecated Kept for historical snapshots / Agent callers only. */
    public const DIRECTION_EVERGREEN = 'evergreen';

    public const CONTENT_TYPE_POST = 'post';

    public const CONTENT_TYPE_PRODUCT = 'product';

    /** @deprecated Legacy snapshot alias for CONTENT_TYPE_POST. */
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

        // UI always automatic; legacy Agent/history may still send seasonal/evergreen.
        $direction = strtolower(trim((string) ($input['direction'] ?? self::DIRECTION_AUTOMATIC)));
        if (! in_array($direction, [self::DIRECTION_AUTOMATIC, self::DIRECTION_SEASONAL, self::DIRECTION_EVERGREEN], true)) {
            $direction = self::DIRECTION_AUTOMATIC;
        }

        $rawType = strtolower(trim((string) (
            $input['content_type']
            ?? $input['post_type']
            ?? self::CONTENT_TYPE_POST
        )));
        $contentType = self::normalizeContentType($rawType);

        $notes = trim((string) ($input['notes'] ?? ''));
        $focus = trim((string) ($input['focus'] ?? ''));
        // Backward compat: old focus → notes when notes empty (does not mutate stored snapshot).
        if ($notes === '' && $focus !== '') {
            $notes = $focus;
        }

        $noteItems = AuditNoteDnaNormalizer::normalizeNoteItems(
            is_array($input['note_items'] ?? null) ? $input['note_items'] : [],
        );

        return [
            'quantity' => max(self::MIN_QUANTITY, min(self::MAX_QUANTITY, $quantity)),
            'direction' => $direction,
            'focus' => $focus,
            'notes' => $notes,
            'note_items' => $noteItems,
            'post_type' => $contentType,
            'content_type' => $contentType,
            'taxonomy' => trim((string) ($input['taxonomy'] ?? '')),
            'use_site_context' => (bool) ($input['use_site_context'] ?? true),
            'use_keyword_intelligence' => (bool) ($input['use_keyword_intelligence'] ?? true),
            'use_mcp_context' => (bool) ($input['use_mcp_context'] ?? true),
        ];
    }

    /**
     * Map planner content type to SeoProjectTask.post_type storage.
     */
    public static function taskPostType(string $contentType): string
    {
        return self::normalizeContentType($contentType) === self::CONTENT_TYPE_PRODUCT
            ? 'product'
            : 'article';
    }

    public static function normalizeContentType(string $raw): string
    {
        $raw = strtolower(trim($raw));
        if ($raw === self::POST_TYPE_ARTICLE || $raw === '') {
            return self::CONTENT_TYPE_POST;
        }
        if ($raw === self::CONTENT_TYPE_PRODUCT) {
            return self::CONTENT_TYPE_PRODUCT;
        }
        if ($raw === self::CONTENT_TYPE_POST) {
            return self::CONTENT_TYPE_POST;
        }

        // page / category / product_category / custom → not AI automation targets
        return self::CONTENT_TYPE_POST;
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
            'content_type' => $normalized['content_type'],
            'post_type' => $normalized['post_type'],
            'notes' => $normalized['notes'],
            'note_items' => $normalized['note_items'],
            'primary_language' => $primaryLanguage,
            'context' => [
                'planning_intelligence' => $normalized['use_keyword_intelligence'],
                'mcp' => $normalized['use_mcp_context'],
                'gsc' => $normalized['use_site_context'],
            ],
            // Legacy keys retained (empty for new UI) so old readers stay safe.
            'direction' => self::DIRECTION_AUTOMATIC,
            'focus' => '',
            'taxonomy' => '',
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
