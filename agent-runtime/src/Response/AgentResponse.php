<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Response;

final readonly class AgentResponse
{
    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array<string, mixed>>  $actions
     * @param  list<array<string, mixed>>  $sources
     */
    public function __construct(
        public string $message,
        public array $blocks,
        public array $actions,
        public array $sources,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $public = (new AgentPublicPayloadSanitizer())->sanitize([
            'message' => $this->message,
            'blocks' => $this->blocks,
            'actions' => $this->actions,
            'sources' => $this->sources,
        ]);

        return is_array($public) ? $public : [
            'message' => $this->message,
            'blocks' => $this->blocks,
            'actions' => $this->actions,
            'sources' => [],
        ];
    }
}
