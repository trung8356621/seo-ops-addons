<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Model;

/**
 * Strips server-only secrets from model-visible text.
 * Temporary access URLs are left intact when they were already model-visible.
 */
final class SecretRedactor
{
    public function redact(string $text): string
    {
        $text = preg_replace('/svc_live_[A-Za-z0-9_\-]+/', '[redacted-service-credential]', $text) ?? $text;
        $text = preg_replace('/\bsk-[A-Za-z0-9_\-]{8,}\b/', '[redacted-provider-key]', $text) ?? $text;
        $text = preg_replace('/(?i)(authorization\s*:\s*bearer\s+)\S+/', '$1[redacted]', $text) ?? $text;
        $text = preg_replace('/(?i)("?(?:api[_-]?key|bearer|access_token)"?\s*[:=]\s*"?)[^"\s,}]+/', '$1[redacted]', $text) ?? $text;

        return $text;
    }
}
