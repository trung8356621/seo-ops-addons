<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\IdeaManager;

/**
 * Result for Keyword → Idea Manager warehouse intake (not Planning Draft).
 */
final class IdeaManagerKeywordIntakeResult
{
    public const STATUS_ADDED = 'added';

    public const STATUS_ALREADY = 'already_in_idea_manager';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    /**
     * @param  array<string, mixed>  $summary
     * @param  list<int>  $keywordIds
     * @param  list<int>  $siteIds
     */
    public function __construct(
        public readonly string $status,
        public readonly array $summary = [],
        public readonly string $message = '',
        public readonly array $keywordIds = [],
        public readonly array $siteIds = [],
    ) {}

    public function isSuccess(): bool
    {
        return in_array($this->status, [
            self::STATUS_ADDED,
            self::STATUS_ALREADY,
            self::STATUS_PARTIAL,
        ], true);
    }

    public function isAlreadyPresent(): bool
    {
        return $this->status === self::STATUS_ALREADY;
    }
}
