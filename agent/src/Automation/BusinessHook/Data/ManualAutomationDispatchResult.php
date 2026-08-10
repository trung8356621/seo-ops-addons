<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Data;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationExecution;

final class ManualAutomationDispatchResult
{
    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_DEDUPLICATED = 'deduplicated';

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $status,
        public readonly string $code,
        public readonly string $message,
        public readonly string $actionCode,
        public readonly ?int $executionId = null,
        public readonly ?string $executionUuid = null,
        public readonly ?string $ruleCode = null,
        public readonly ?string $historyUrl = null,
        public readonly array $context = [],
    ) {}

    public function isDispatched(): bool
    {
        return $this->status === self::STATUS_DISPATCHED;
    }

    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED;
    }

    public function isDeduplicated(): bool
    {
        return $this->status === self::STATUS_DEDUPLICATED;
    }

    public static function fromExecution(
        string $status,
        AutomationExecution $execution,
        string $actionCode,
        string $message,
        string $code = 'OK',
        ?string $ruleCode = null,
        ?string $historyUrl = null,
    ): self {
        return new self(
            status: $status,
            code: $code,
            message: $message,
            actionCode: $actionCode,
            executionId: (int) $execution->id,
            executionUuid: (string) $execution->execution_uuid,
            ruleCode: $ruleCode,
            historyUrl: $historyUrl,
        );
    }

    public static function blocked(
        string $actionCode,
        string $code,
        string $message,
        ?string $ruleCode = null,
        array $context = [],
    ): self {
        return new self(
            status: self::STATUS_BLOCKED,
            code: $code,
            message: $message,
            actionCode: $actionCode,
            ruleCode: $ruleCode,
            context: $context,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->status !== self::STATUS_BLOCKED,
            'status' => $this->status,
            'code' => $this->code,
            'message' => $this->message,
            'action_code' => $this->actionCode,
            'queued' => in_array($this->status, [self::STATUS_DISPATCHED, self::STATUS_DEDUPLICATED], true),
            'automation_execution_id' => $this->executionId,
            'execution_uuid' => $this->executionUuid,
            'rule_code' => $this->ruleCode,
            'automation_history_url' => $this->historyUrl,
            'error_code' => $this->status === self::STATUS_BLOCKED ? $this->code : null,
        ];
    }
}
