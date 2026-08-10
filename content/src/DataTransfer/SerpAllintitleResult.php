<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\DataTransfer;

final class SerpAllintitleResult
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_NOT_FOUND = 'not_found';

    public const STATUS_NOT_SUPPORTED = 'not_supported';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        public readonly string $provider,
        public readonly string $keyword,
        public readonly ?int $estimatedResults,
        public readonly string $status,
        public readonly ?string $errorMessage = null,
        public readonly int $durationMs = 0,
        public readonly array $metadata = [],
    ) {}
}
