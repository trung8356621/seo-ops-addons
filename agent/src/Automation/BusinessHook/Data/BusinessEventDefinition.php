<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Data;

final class BusinessEventDefinition
{
    /**
     * @param  array<string, array{type?: string, required?: bool}>  $payloadSchema
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $subject,
        public readonly array $payloadSchema,
        public readonly string $description,
        public readonly string $module,
    ) {}
}
