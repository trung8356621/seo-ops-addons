<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Extension\Contracts\Ai;

final class AiExecutionContext
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $providerKey,
        public readonly ?int $connectionId = null,
        public readonly ?int $siteId = null,
        public readonly array $metadata = [],
        public readonly ?object $connection = null,
        public readonly ?object $prompt = null,
        public readonly bool $isTaskMode = false,
        /** @var array<string, string> */
        public readonly array $variables = [],
    ) {}
}
