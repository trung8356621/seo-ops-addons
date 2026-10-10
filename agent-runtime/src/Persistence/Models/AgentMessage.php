<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Omnichannel\Addons\AgentRuntime\Response\AgentPublicPayloadSanitizer;

class AgentMessage extends Model
{
    protected $table = 'agent_messages';

    protected $guarded = [];

    protected $casts = [
        'response_payload' => 'array',
        'metadata' => 'array',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(AgentThread::class, 'thread_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'run_id');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $sanitizer = new AgentPublicPayloadSanitizer();
        $data = parent::toArray();
        if (is_string($data['content'] ?? null)) {
            $content = $sanitizer->sanitize($data['content']);
            $data['content'] = is_string($content) ? $content : '';
        }
        if (array_key_exists('response_payload', $data)) {
            $data['response_payload'] = $sanitizer->sanitize($data['response_payload']);
        }

        return $data;
    }
}
