<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Decision;

final readonly class DecisionResult
{
    public function __construct(
        public bool $ok,
        public string $rawText = '',
        public ?string $failureCode = null,
    ) {}

    public static function failed(string $code): self
    {
        return new self(false, '', $code);
    }
}
