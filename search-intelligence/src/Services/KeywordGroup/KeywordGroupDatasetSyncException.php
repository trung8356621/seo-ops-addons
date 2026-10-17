<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup;

final class KeywordGroupDatasetSyncException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
