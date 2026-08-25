<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection;

/**
 * Non-index operational failure for URL Inspection HTTP/API.
 */
final class GscUrlInspectionApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus = 0,
        public readonly bool $rateLimited = false,
        public readonly bool $permissionDenied = false,
        public readonly bool $transient = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function missingBinding(string $message): self
    {
        return new self($message, 'gsc.property_missing', 0, false, true, false);
    }

    public static function permission(string $message, int $status = 403): self
    {
        return new self($message, 'gsc.permission_denied', $status, false, true, false);
    }

    public static function rateLimited(string $message, int $status = 429): self
    {
        return new self($message, 'gsc.rate_limited', $status, true, false, true);
    }

    public static function transient(string $message, int $status = 500): self
    {
        return new self($message, 'gsc.transient_error', $status, false, false, true);
    }

    public static function http(string $message, int $status): self
    {
        if ($status === 401 || $status === 403) {
            return self::permission($message, $status);
        }
        if ($status === 429) {
            return self::rateLimited($message, $status);
        }
        if ($status >= 500 || $status === 0) {
            return self::transient($message, $status);
        }

        return new self($message, 'gsc.http_error', $status, false, false, false);
    }
}
