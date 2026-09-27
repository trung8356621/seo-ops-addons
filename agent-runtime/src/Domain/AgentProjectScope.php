<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Domain;

use InvalidArgumentException;

/**
 * Agent project scope.
 *
 * global is its own type. It is not site_id 0 and not a null site.
 */
final readonly class AgentProjectScope
{
    private function __construct(
        public string $type,
        public ?int $siteId = null,
        public ?string $siteRef = null,
    ) {}

    public static function global(): self
    {
        return new self('global');
    }

    public static function site(int $siteId): self
    {
        if ($siteId <= 0) {
            throw new InvalidArgumentException('Site scope requires a positive site id.');
        }

        return new self('site', $siteId, 'site:'.$siteId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $type = strtolower(trim((string) ($payload['type'] ?? '')));
        if ($type === 'global') {
            return self::global();
        }
        if ($type === 'site') {
            $siteId = (int) ($payload['siteId'] ?? $payload['site_id'] ?? 0);
            if ($siteId <= 0) {
                $ref = (string) ($payload['ref'] ?? $payload['siteRef'] ?? $payload['site_ref'] ?? '');
                if (preg_match('/^site:(\d+)$/', $ref, $matches)) {
                    $siteId = (int) $matches[1];
                }
            }

            return self::site($siteId);
        }

        throw new InvalidArgumentException('Project scope type must be global or site.');
    }

    public function isGlobal(): bool
    {
        return $this->type === 'global';
    }

    public function isSite(): bool
    {
        return $this->type === 'site';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->isGlobal()) {
            return ['type' => 'global'];
        }

        return [
            'type' => 'site',
            'site_id' => $this->siteId,
            'site_ref' => $this->siteRef,
        ];
    }
}
