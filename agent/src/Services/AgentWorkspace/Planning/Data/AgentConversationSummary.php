<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data;

final readonly class AgentConversationSummary
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $text,
        public int $version,
        public ?int $untilMessageId = null,
        public array $payload = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'version' => $this->version,
            'until_message_id' => $this->untilMessageId,
            'payload' => $this->payload,
        ];
    }
}
