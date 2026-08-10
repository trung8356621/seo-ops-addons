<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Application\Publishing;

final class PublishResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?int $wpPostId,
        public readonly string $message,
        public readonly bool $alreadyPublished = false,
        public readonly ?string $externalReference = null,
        public readonly bool $deliveryRequested = false,
        public readonly ?string $permalink = null,
    ) {}
}
