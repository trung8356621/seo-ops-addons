<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\SiteHealth;

final readonly class SiteHealthResult
{
    /** @param array<string, array{status: string, detail?: string}> $stages */
    public function __construct(
        public bool $healthy,
        public string $status,
        public string $severity,
        public ?string $errorCode,
        public string $reason,
        public array $stages,
        public ?string $technicalError = null,
    ) {}

    /** @param array<string, array{status: string, detail?: string}> $stages */
    public static function ok(array $stages): self
    {
        return new self(true, 'healthy', 'info', null, 'OK', $stages);
    }

    /** @param array<string, array{status: string, detail?: string}> $stages */
    public static function failed(string $status, string $severity, string $code, string $reason, array $stages, ?string $technical = null): self
    {
        return new self(false, $status, $severity, $code, $reason, $stages, SiteHealthSanitizer::clean($technical));
    }
}
