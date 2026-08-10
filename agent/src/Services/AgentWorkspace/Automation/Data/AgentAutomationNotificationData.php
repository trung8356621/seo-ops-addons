<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data;

final readonly class AgentAutomationNotificationData
{
    /**
     * @param  list<string>  $destinations
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $policy,
        public array $destinations,
        public string $title,
        public string $body,
        public string $severity,
        public string $fingerprint,
        public array $payload,
        public ?string $runHashId = null,
        public ?string $automationHashId = null,
        public bool $delayed = false,
        public ?string $deliverAt = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'policy' => $this->policy,
            'destinations' => $this->destinations,
            'title' => $this->title,
            'body' => $this->body,
            'severity' => $this->severity,
            'fingerprint' => $this->fingerprint,
            'payload' => $this->payload,
            'run_hash_id' => $this->runHashId,
            'automation_hash_id' => $this->automationHashId,
            'delayed' => $this->delayed,
            'deliver_at' => $this->deliverAt,
        ];
    }
}
