<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Retrieval;

final readonly class RetrievalSource
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $name,
        public string $status,
        public string $request,
        public array $data = [],
        public ?string $reason = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $row = [
            'name' => $this->name,
            'status' => $this->status,
            'request' => $this->request,
            'data' => $this->data,
        ];
        if ($this->reason !== null && $this->reason !== '') {
            $row['reason'] = $this->reason;
        }

        return $row;
    }
}
