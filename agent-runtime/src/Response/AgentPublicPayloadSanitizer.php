<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Response;

/**
 * Removes credential-bearing SEO Access URLs from client-visible payloads.
 * Safe UI links and public article URLs stay.
 */
final class AgentPublicPayloadSanitizer
{
    public function sanitize(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->redact($value);
        }
        if (! is_array($value)) {
            return $value;
        }

        $clean = [];
        foreach ($value as $key => $item) {
            $sanitized = $this->sanitize($item);
            if ($sanitized === null && is_string($item)) {
                continue;
            }
            $clean[$key] = $sanitized;
        }

        return $clean;
    }

    private function redact(string $value): ?string
    {
        $redacted = preg_replace('#/api/v1/access/\S+#', '', $value) ?? $value;
        $redacted = preg_replace('#\S*access_tmp\S*#', '', $redacted) ?? $redacted;
        if (trim($redacted) === '') {
            return null;
        }

        return $redacted === $value ? $value : trim($redacted);
    }
}
