<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services\SideEffect;

use RuntimeException;

final class UnauthorizedWordPressSideEffectException extends RuntimeException
{
    public const ORIGIN_MISSING = 'WORDPRESS_SIDE_EFFECT_ORIGIN_MISSING';

    public const ORIGIN_INVALID = 'WORDPRESS_SIDE_EFFECT_ORIGIN_INVALID';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $traceContext = [],
    ) {
        parent::__construct("[{$errorCode}] {$message}");
    }
}
