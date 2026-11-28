<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Http;

final readonly class AgentQaContext
{
    public const REQUEST_ATTRIBUTE = 'agent_qa_context';

    /**
     * @param  list<int>|null  $allowedSiteIds  Null means all sites accessible to the mapped user.
     */
    public function __construct(
        public int $userId,
        public ?array $allowedSiteIds,
    ) {}

    public function allowsSiteId(int $siteId): bool
    {
        if ($siteId <= 0) {
            return false;
        }

        if ($this->allowedSiteIds === null) {
            return true;
        }

        return in_array($siteId, $this->allowedSiteIds, true);
    }

    /**
     * @return list<int>|null
     */
    public static function parseAllowedSiteIds(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        if (is_array($raw)) {
            $ids = [];
            foreach ($raw as $item) {
                if (is_numeric($item) && (int) $item > 0) {
                    $ids[] = (int) $item;
                }
            }

            return array_values(array_unique($ids));
        }

        $trimmed = trim((string) $raw);
        if ($trimmed === '' || $trimmed === '*') {
            return null;
        }

        $ids = [];
        foreach (explode(',', $trimmed) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (ctype_digit($part) && (int) $part > 0) {
                $ids[] = (int) $part;
            }
        }

        return array_values(array_unique($ids));
    }
}
