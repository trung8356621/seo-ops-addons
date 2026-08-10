<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Services\Publishing;

/**
 * One observable outcome per due item — no silent no-op.
 */
final class PublishDueItemOutcome
{
    public const PUBLISHED = 'published';

    public const RETRY_WAIT = 'retry_wait';

    public const FAILED = 'failed';

    public const AWAITING_DELIVERY = 'awaiting_delivery';

    public const SKIPPED = 'skipped';

    public const ERROR = 'error';

    public function __construct(
        public readonly int $itemId,
        public readonly string $trigger,
        public readonly string $outcome,
        public readonly string $reason = '',
        public readonly bool $publisherInvoked = false,
        public readonly bool $claimSuccess = false,
        public readonly string $claimCode = '',
        public readonly string $finalStatus = '',
        public readonly ?string $exceptionClass = null,
        public readonly ?string $exceptionMessage = null,
        /** @var array<string, mixed> */
        public readonly array $meta = [],
    ) {}

    public function isSuccessPath(): bool
    {
        return in_array($this->outcome, [
            self::PUBLISHED,
            self::AWAITING_DELIVERY,
            self::RETRY_WAIT,
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toLogArray(): array
    {
        return [
            'item_id' => $this->itemId,
            'trigger' => $this->trigger,
            'outcome' => $this->outcome,
            'reason' => $this->reason,
            'publisher_invoked' => $this->publisherInvoked,
            'claim_success' => $this->claimSuccess,
            'claim_code' => $this->claimCode,
            'final_status' => $this->finalStatus,
            'exception_class' => $this->exceptionClass,
            'exception_message' => $this->exceptionMessage,
            'meta' => $this->meta,
        ];
    }
}
