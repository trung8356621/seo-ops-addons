<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions;

/** Connection refused, DNS, or service not reachable. */
final class SemanticUnavailableException extends SemanticHttpException
{
    public static function connectionFailed(string $detail, ?\Throwable $previous = null): self
    {
        return new self(
            'Semantic service unavailable: '.$detail,
            'semantic_unavailable',
            null,
            $previous,
        );
    }
}
