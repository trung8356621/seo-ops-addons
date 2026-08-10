<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Data;

final class AutomationAvailabilityResult
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $actionCode = null,
        public readonly ?string $ruleCode = null,
        public readonly ?int $ruleId = null,
        public readonly ?int $publishedVersionId = null,
        public readonly array $context = [],
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public static function allow(
        string $code = 'OK',
        string $message = 'Automation available.',
        ?string $actionCode = null,
        ?string $ruleCode = null,
        ?int $ruleId = null,
        ?int $publishedVersionId = null,
        array $context = [],
    ): self {
        return new self(
            allowed: true,
            code: $code,
            message: $message,
            actionCode: $actionCode,
            ruleCode: $ruleCode,
            ruleId: $ruleId,
            publishedVersionId: $publishedVersionId,
            context: $context,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function block(
        string $code,
        string $message,
        ?string $actionCode = null,
        ?string $ruleCode = null,
        ?int $ruleId = null,
        ?int $publishedVersionId = null,
        array $context = [],
    ): self {
        return new self(
            allowed: false,
            code: $code,
            message: $message,
            actionCode: $actionCode,
            ruleCode: $ruleCode,
            ruleId: $ruleId,
            publishedVersionId: $publishedVersionId,
            context: $context,
        );
    }
}
