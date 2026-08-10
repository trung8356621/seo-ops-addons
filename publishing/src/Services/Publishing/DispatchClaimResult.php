<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Services\Publishing;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;

/**
 * Structured result of claimForDispatch — never silent null.
 */
final class DispatchClaimResult
{
    public const CLAIMED = 'claimed';

    public const ACTIVE_PUBLISH = 'active_publish';

    public const LOCK_BUSY = 'lock_busy';

    public const STALE_CLAIM = 'stale_claim';

    public const STALE_OPERATION = 'stale_operation';

    public const NOT_DUE = 'not_due';

    public const INVALID_STATUS = 'invalid_status';

    public const ATTEMPTS_EXHAUSTED = 'attempts_exhausted';

    public const MISSING_ARTICLE = 'missing_article';

    public const MISSING_CONNECTION = 'missing_connection';

    public const AWAITING_WORKER = 'awaiting_worker';

    public const DISPATCH_FAILED = 'dispatch_failed';

    public const NOT_FOUND = 'not_found';

    public function __construct(
        public readonly string $code,
        public readonly ?SeoProjectTask $task = null,
        public readonly string $message = '',
        /** @var array<string, mixed> */
        public readonly array $meta = [],
    ) {}

    public static function claimed(SeoProjectTask $task): self
    {
        return new self(self::CLAIMED, $task, 'Claimed for delivery dispatch.');
    }

    public static function rejected(string $code, string $message, ?SeoProjectTask $task = null, array $meta = []): self
    {
        return new self($code, $task, $message, $meta);
    }

    public function isClaimed(): bool
    {
        return $this->code === self::CLAIMED && $this->task instanceof SeoProjectTask;
    }
}
