<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions;

/** Connected but total request timeout exceeded (e.g. cold model / long analysis). */
final class SemanticTimeoutException extends SemanticHttpException
{
    public static function requestTimedOut(string $detail, ?\Throwable $previous = null): self
    {
        return new self(
            'Semantic request timed out: '.$detail,
            'semantic_timeout',
            null,
            $previous,
        );
    }
}
