<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\DataTransfer;

use DateTimeInterface;

final readonly class SerpRankResult
{
    public const STATUS_SUCCESS_FOUND = 'success_found';

    public const STATUS_SUCCESS_NOT_FOUND = 'success_not_found';

    public const STATUS_INVALID_CREDENTIALS = 'invalid_credentials';

    public const STATUS_QUOTA_EXHAUSTED = 'quota_exhausted';

    public const STATUS_RATE_LIMITED = 'rate_limited';

    public const STATUS_PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public const STATUS_MALFORMED_RESPONSE = 'malformed_response';

    public const STATUS_TIMEOUT = 'timeout';

    /**
     * @param  list<SerpOrganicResult>  $organicResults
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $provider,
        public string $keyword,
        public DateTimeInterface $checkedAt,
        public ?string $country,
        public ?string $language,
        public ?string $location,
        public ?string $device,
        public array $organicResults,
        public ?float $trackedDomainBestPosition,
        public ?string $trackedUrl,
        public ?int $resultCount,
        public string $status,
        public ?string $errorMessage,
        public int $durationMs,
        public array $metadata = [],
    ) {}

    public function isRetryable(): bool
    {
        return in_array($this->status, [
            self::STATUS_RATE_LIMITED,
            self::STATUS_PROVIDER_UNAVAILABLE,
            self::STATUS_TIMEOUT,
        ], true);
    }
}
