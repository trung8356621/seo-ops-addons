<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions;

use RuntimeException;

/**
 * Base transport/contract failure talking to seo-ops-semantic.
 * Not a Topic business exception.
 */
class SemanticHttpException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
