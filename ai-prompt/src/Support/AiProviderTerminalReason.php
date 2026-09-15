<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Cross-provider normalized terminal outcome for a physical route attempt.
 * Persisted on usage / routing attempts — not provider-specific raw strings.
 */
enum AiProviderTerminalReason: string
{
    case Completed = 'completed';
    case OutputTruncated = 'output_truncated';
    case InsufficientCredit = 'insufficient_credit';
    case QuotaLimited = 'quota_limited';
    case RateLimited = 'rate_limited';
    case ProviderResourceLimited = 'provider_resource_limited';
}
