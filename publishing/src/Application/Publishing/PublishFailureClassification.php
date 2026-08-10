<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Application\Publishing;

use Carbon\CarbonInterface;

/**
 * Canonical publish failure classification result.
 */
final class PublishFailureClassification
{
    public function __construct(
        public readonly bool $retryable,
        public readonly string $code,
        public readonly string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?CarbonInterface $retryAfter = null,
    ) {}
}
