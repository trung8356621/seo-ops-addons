<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

use Omnichannel\Addons\Agent\Automation\Support\SensitivePayloadRedactor;
use Illuminate\Support\Facades\Log;

/**
 * Audit/execution log only — NO article/task/project domain attachment.
 */
final class PromptHookAuditRecorder
{
    public function __construct(
        private readonly SensitivePayloadRedactor $redactor = new SensitivePayloadRedactor,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(array $payload): string
    {
        $safe = $this->redactor->redact($payload);
        // Never persist full prompt/response by default
        unset($safe['full_prompt'], $safe['full_response'], $safe['raw_output']);

        $fingerprint = hash('sha256', (string) json_encode([
            'hook_key' => $safe['hook_key'] ?? null,
            'hook_version' => $safe['hook_version'] ?? null,
            'mode' => $safe['mode'] ?? null,
            'correlation_id' => $safe['correlation_id'] ?? null,
            'validation_status' => $safe['validation_status'] ?? null,
        ]));

        Log::info('prompt_hook.execution_audit', array_merge($safe, [
            'fingerprint' => $fingerprint,
        ]));

        return $fingerprint;
    }
}
