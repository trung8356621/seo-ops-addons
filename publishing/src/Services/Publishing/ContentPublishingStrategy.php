<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Services\Publishing;

final class ContentPublishingStrategy
{
    public const SCHEDULED_CREATE = 'scheduled_create';

    public const IMMEDIATE_UPDATE = 'immediate_update';

    public const FAILED_MISSING_REMOTE = 'failed_missing_remote';

    public function __construct(
        public readonly string $mode,
        public readonly ?int $remotePostId = null,
        public readonly ?string $message = null,
    ) {}

    public function isImmediateUpdate(): bool
    {
        return $this->mode === self::IMMEDIATE_UPDATE;
    }

    public function isMissingRemote(): bool
    {
        return $this->mode === self::FAILED_MISSING_REMOTE;
    }
}
