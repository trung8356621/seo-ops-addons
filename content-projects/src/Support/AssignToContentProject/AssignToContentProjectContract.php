<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\AssignToContentProject;

/**
 * CANONICAL open contract for Assign-to-Content-Project UI.
 *
 * All modules MUST open the shared drawer via OPEN_EVENT with a normalized payload.
 * Do not invent parallel modals, drawers, form schemas, or event names.
 */
final class AssignToContentProjectContract
{
    public const OPEN_EVENT = 'assign-content-project:open';

    public const SUCCESS_EVENT = 'assign-content-project:success';

    public const CLOSE_EVENT = 'assign-content-project:close';

    /** Fired when the Assign drawer shell becomes visible (before Livewire hydrate). */
    public const SHELL_OPEN_EVENT = 'assign-content-project:shell-open';

    /** Fired when the Assign drawer shell is hidden (cancel, X, or success). */
    public const SHELL_CLOSE_EVENT = 'assign-content-project:shell-close';

    public const ICON = 'heroicon-o-folder-plus';

    public const COLOR = 'warning';

    public const MODE_ARTICLE = 'article';

    public const MODE_KEYWORD = 'keyword';

    public const MODE_PENDING_LINK = 'pending_link';

    /** Batch planning phrases (Vocabulary → Lập kế hoạch). Creates TYPE_CREATE items only. */
    public const MODE_VOCABULARY_ITEMS = 'vocabulary_items';

    public const LABEL_KEY = 'seo-content-ai::filament.article_list.assign_to_content_project';

    public static function label(): string
    {
        return __(self::LABEL_KEY);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function openScript(array $payload): string
    {
        $normalized = self::normalizePayload($payload);

        return 'window.dispatchEvent(new CustomEvent('
            .json_encode(self::OPEN_EVENT)
            .',{detail:'
            .json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            .'}));';
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array{
     *     mode: string,
     *     source: string,
     *     article_ids: list<int>,
     *     keyword_ids: list<int>,
     *     site_ids: list<int>,
     *     map_id: int|null,
     *     anchor_phrase: string|null,
     *     items: list<array{keyword: string, title: string, source: string, source_article_id: int|null}>,
     *     defaults: array<string, mixed>,
     *     options: array<string, mixed>
     * }
     */
    public static function normalizePayload(array $detail): array
    {
        $mode = (string) ($detail['mode'] ?? self::MODE_ARTICLE);
        if (! in_array($mode, [
            self::MODE_ARTICLE,
            self::MODE_KEYWORD,
            self::MODE_PENDING_LINK,
            self::MODE_VOCABULARY_ITEMS,
        ], true)) {
            $mode = self::MODE_ARTICLE;
        }

        $defaults = is_array($detail['defaults'] ?? null) ? $detail['defaults'] : [];
        $options = is_array($detail['options'] ?? null) ? $detail['options'] : [];

        return [
            'mode' => $mode,
            'source' => trim((string) ($detail['source'] ?? '')),
            'article_ids' => self::normalizeIdList($detail['article_ids'] ?? []),
            'keyword_ids' => self::normalizeIdList($detail['keyword_ids'] ?? []),
            'site_ids' => self::normalizeIdList($detail['site_ids'] ?? []),
            'map_id' => self::nullablePositiveInt($detail['map_id'] ?? null),
            'anchor_phrase' => self::nullableTrimmedString($detail['anchor_phrase'] ?? null),
            'items' => self::normalizeItems($detail['items'] ?? []),
            'defaults' => $defaults,
            'options' => $options,
        ];
    }

    /**
     * @param  list<int>  $articleIds
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function articlePayload(
        string $source,
        array $articleIds,
        ?int $siteId = null,
        array $defaults = [],
        array $options = [],
    ): array {
        return self::normalizePayload([
            'mode' => self::MODE_ARTICLE,
            'source' => $source,
            'article_ids' => $articleIds,
            'site_ids' => $siteId !== null && $siteId > 0 ? [$siteId] : [],
            'defaults' => $defaults,
            'options' => $options,
        ]);
    }

    /**
     * @param  list<int>  $keywordIds
     * @param  list<int>  $siteIds
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function keywordPayload(
        string $source,
        array $keywordIds,
        array $siteIds = [],
        ?int $mapId = null,
        array $defaults = [],
        array $options = [],
    ): array {
        return self::normalizePayload([
            'mode' => self::MODE_KEYWORD,
            'source' => $source,
            'keyword_ids' => $keywordIds,
            'site_ids' => $siteIds,
            'map_id' => $mapId,
            'defaults' => $defaults,
            'options' => $options,
        ]);
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function pendingLinkPayload(
        string $source,
        int $articleId,
        string $anchorPhrase,
        ?int $siteId = null,
        array $defaults = [],
        array $options = [],
    ): array {
        return self::normalizePayload([
            'mode' => self::MODE_PENDING_LINK,
            'source' => $source,
            'article_ids' => [$articleId],
            'site_ids' => $siteId !== null && $siteId > 0 ? [$siteId] : [],
            'anchor_phrase' => $anchorPhrase,
            'defaults' => $defaults,
            'options' => $options,
        ]);
    }

    /**
     * @param  list<array<string, mixed>|string>  $items
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function vocabularyItemsPayload(
        string $source,
        array $items,
        ?int $siteId = null,
        ?int $sourceArticleId = null,
        array $defaults = [],
        array $options = [],
    ): array {
        $normalizedItems = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $normalizedItems[] = [
                    'keyword' => $item,
                    'title' => $item,
                    'source' => 'vocabulary',
                    'source_article_id' => $sourceArticleId,
                ];
                continue;
            }
            if (! is_array($item)) {
                continue;
            }
            $phrase = trim((string) ($item['keyword'] ?? $item['phrase'] ?? $item['title'] ?? ''));
            if ($phrase === '') {
                continue;
            }
            $normalizedItems[] = [
                'keyword' => $phrase,
                'title' => trim((string) ($item['title'] ?? $phrase)) ?: $phrase,
                'source' => trim((string) ($item['source'] ?? 'vocabulary')) ?: 'vocabulary',
                'source_article_id' => self::nullablePositiveInt(
                    $item['source_article_id'] ?? $sourceArticleId
                ),
            ];
        }

        return self::normalizePayload([
            'mode' => self::MODE_VOCABULARY_ITEMS,
            'source' => $source,
            'article_ids' => $sourceArticleId !== null && $sourceArticleId > 0 ? [$sourceArticleId] : [],
            'site_ids' => $siteId !== null && $siteId > 0 ? [$siteId] : [],
            'items' => $normalizedItems,
            'defaults' => array_merge(['type' => 'create'], $defaults),
            'options' => array_merge([
                'show_article_fields' => false,
                'show_quick_create' => true,
                'show_keyword_override' => false,
                'show_title_override' => false,
            ], $options),
        ]);
    }

    /**
     * @param  mixed  $value
     * @return list<array{keyword: string, title: string, source: string, source_article_id: int|null}>
     */
    private static function normalizeItems(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $phrase = trim($item);
                if ($phrase === '') {
                    continue;
                }
                $out[] = [
                    'keyword' => $phrase,
                    'title' => $phrase,
                    'source' => 'vocabulary',
                    'source_article_id' => null,
                ];
                continue;
            }
            if (! is_array($item)) {
                continue;
            }
            $phrase = trim((string) ($item['keyword'] ?? $item['phrase'] ?? $item['title'] ?? ''));
            if ($phrase === '') {
                continue;
            }
            $title = trim((string) ($item['title'] ?? $phrase));
            $out[] = [
                'keyword' => $phrase,
                'title' => $title !== '' ? $title : $phrase,
                'source' => trim((string) ($item['source'] ?? 'vocabulary')) ?: 'vocabulary',
                'source_article_id' => self::nullablePositiveInt($item['source_article_id'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * @param  mixed  $value
     * @return list<int>
     */
    private static function normalizeIdList(mixed $value): array
    {
        if (! is_array($value)) {
            if (is_numeric($value) && (int) $value > 0) {
                return [(int) $value];
            }

            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $value),
            static fn (int $id): bool => $id > 0,
        )));
    }

    private static function nullablePositiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private static function nullableTrimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
