<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemIdentity;

/**
 * Pure classification for forensic recovery of legacy Content Project tasks.
 */
final class ContentProjectLegacyTaskClassifier
{
    public const PROMPT_KEYWORD_OK = 'prompt_keyword_ok';

    public const PROMPT_KEYWORD_MISSING = 'legacy_prompt_keyword_binding_corruption';

    public const PROMPT_KEYWORD_UNKNOWN = 'prompt_keyword_unknown';

    public const ORDER_ARTICLE_BEFORE_PROMPT = 'article_bound_before_prompt';

    public const ORDER_PROMPT_BEFORE_ARTICLE = 'prompt_before_stable_article';

    public const ORDER_NEVER_BOUND = 'article_never_successfully_bound';

    public const ORDER_UNKNOWN = 'creation_order_unknown';

    public const COLLISION_STALE_ID = 'stale_article_id_collision_after_partial_restore';

    public const COLLISION_CROSS_SITE = 'cross_site_article_association';

    public const COLLISION_PROVEN = 'independent_provenance_ok';

    public const COLLISION_NONE = 'no_current_article';

    public static function keywordsMatch(?string $taskKeyword, ?string $promptKeyword): bool
    {
        $task = ContentProjectItemIdentity::normalize($taskKeyword);
        $prompt = ContentProjectItemIdentity::normalize($promptKeyword);
        if ($task === '' || $prompt === '') {
            return false;
        }

        return mb_strtolower($task) === mb_strtolower($prompt);
    }

    public static function classifyPromptKeyword(?string $taskKeyword, ?string $promptKeyword): string
    {
        $task = ContentProjectItemIdentity::normalize($taskKeyword);
        if ($task === '') {
            return self::PROMPT_KEYWORD_UNKNOWN;
        }
        if ($promptKeyword === null) {
            return self::PROMPT_KEYWORD_UNKNOWN;
        }
        $prompt = ContentProjectItemIdentity::normalize($promptKeyword);
        if ($prompt === '') {
            return self::PROMPT_KEYWORD_MISSING;
        }

        return self::keywordsMatch($task, $prompt)
            ? self::PROMPT_KEYWORD_OK
            : self::PROMPT_KEYWORD_MISSING;
    }

    public static function classifyCreationOrder(
        ?string $firstPromptAt,
        ?string $articleCreatedAt,
        ?string $articleBoundAt,
    ): string {
        $promptTs = self::toTs($firstPromptAt);
        $createdTs = self::toTs($articleCreatedAt);
        $boundTs = self::toTs($articleBoundAt);
        $articleTs = $boundTs ?? $createdTs;

        if ($promptTs === null && $articleTs === null) {
            return self::ORDER_UNKNOWN;
        }
        if ($articleTs === null && $promptTs !== null) {
            return self::ORDER_PROMPT_BEFORE_ARTICLE;
        }
        if ($promptTs === null && $articleTs !== null) {
            return self::ORDER_ARTICLE_BEFORE_PROMPT;
        }
        if ($promptTs !== null && $articleTs !== null && $promptTs < $articleTs) {
            return self::ORDER_PROMPT_BEFORE_ARTICLE;
        }
        if ($promptTs !== null && $articleTs !== null && $articleTs <= $promptTs) {
            return self::ORDER_ARTICLE_BEFORE_PROMPT;
        }

        return self::ORDER_UNKNOWN;
    }

    /**
     * Numeric task.article_id is never proof. Independent provenance required.
     *
     * @param  array{
     *     task_article_id?: int,
     *     current_article_id?: int,
     *     current_article_site_id?: int,
     *     project_site_id?: int,
     *     independent_provenance?: bool,
     *     semantic_match?: bool,
     *     article_row_exists?: bool
     * }  $row
     */
    public static function classifyCurrentArticle(array $row): string
    {
        $currentId = (int) ($row['current_article_id'] ?? $row['task_article_id'] ?? 0);
        if ($currentId <= 0 || empty($row['article_row_exists'])) {
            return self::COLLISION_NONE;
        }

        $projectSiteId = (int) ($row['project_site_id'] ?? 0);
        $articleSiteId = (int) ($row['current_article_site_id'] ?? 0);
        $independent = ! empty($row['independent_provenance']);
        $semantic = ! empty($row['semantic_match']);

        if ($independent && $articleSiteId === $projectSiteId && $semantic) {
            return self::COLLISION_PROVEN;
        }

        if ($articleSiteId > 0 && $projectSiteId > 0 && $articleSiteId !== $projectSiteId && ! $independent) {
            return self::COLLISION_STALE_ID;
        }

        if ($articleSiteId !== $projectSiteId) {
            return self::COLLISION_CROSS_SITE;
        }

        if (! $independent) {
            return self::COLLISION_STALE_ID;
        }

        return self::COLLISION_PROVEN;
    }

    /**
     * Generated titles from a keyword-binding bug must not auto-relink CREATE items.
     */
    public static function exactTitleFallbackAllowed(string $taskType, string $promptKeywordClass): bool
    {
        $type = \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask::normalizeType($taskType);
        if ($type !== \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask::TYPE_CREATE) {
            return true;
        }

        return $promptKeywordClass !== self::PROMPT_KEYWORD_MISSING;
    }

    private static function toTs(?string $value): ?int
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        $ts = strtotime($raw);

        return $ts !== false ? $ts : null;
    }
}
