<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos;

final class AgentSkillAvailability
{
    public const AVAILABLE = 'available';

    public const PERMISSION_DENIED = 'permission_denied';

    public const NOT_CONFIGURED = 'not_configured';

    public const PROVIDER_UNHEALTHY = 'provider_unhealthy';

    public const EXTENSION_DISABLED = 'extension_disabled';

    public const NOT_IMPLEMENTED = 'not_implemented';

    public const HIDDEN = 'hidden';

    public const COMING_SOON = 'coming_soon';

    public const WRONG_CONTEXT = 'wrong_context';

    public const QUOTA_EXCEEDED = 'quota_exceeded';

    public function __construct(
        public readonly string $status,
        public readonly string $reason = '',
        public readonly bool $usable = false,
    ) {}

    public static function available(): self
    {
        return new self(self::AVAILABLE, '', true);
    }

    public static function of(string $status, string $reason = ''): self
    {
        return new self($status, $reason, $status === self::AVAILABLE);
    }

    /**
     * @return array{status: string, reason: string, usable: bool}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'reason' => $this->reason,
            'usable' => $this->usable,
        ];
    }
}
