<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup;

final class KeywordGroupAnalysisException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
