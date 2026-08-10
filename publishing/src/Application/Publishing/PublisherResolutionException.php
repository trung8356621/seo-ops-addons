<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Application\Publishing;

use RuntimeException;

/**
 * Fail-closed publisher resolution — không silent fallback WordPress.
 */
final class PublisherResolutionException extends RuntimeException
{
    public const NOT_CONFIGURED = 'publisher.not_configured';

    public const NOT_REGISTERED = 'publisher.not_registered';

    public const DISABLED = 'publisher.disabled';

    public const INCOMPATIBLE = 'publisher.incompatible';

    public const UNHEALTHY = 'publisher.unhealthy';

    public const OPERATION_UNSUPPORTED = 'publisher.operation_unsupported';

    public function __construct(
        public readonly string $resultCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(string $detail = ''): self
    {
        return new self(self::NOT_CONFIGURED, $detail !== '' ? $detail : 'Publisher key not configured for site.');
    }

    public static function notRegistered(string $key): self
    {
        return new self(self::NOT_REGISTERED, 'Publisher ['.$key.'] is not registered.');
    }

    public static function disabled(string $key): self
    {
        return new self(self::DISABLED, 'Publisher extension ['.$key.'] is disabled.');
    }

    public static function unhealthy(string $key, string $message): self
    {
        return new self(self::UNHEALTHY, 'Publisher ['.$key.'] unhealthy: '.$message);
    }
}
