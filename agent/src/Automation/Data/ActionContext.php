<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Data;

use Omnichannel\Addons\Agent\Automation\Enums\PublishIntent;
use Omnichannel\Addons\Agent\Automation\Support\CanonicalIds;
use Illuminate\Support\Str;

/**
 * Canonical context IDs: team_id?, site_id, connection_id (không website_id/domain_id).
 */
final class ActionContext
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $executionId,
        public readonly string $correlationId,
        public readonly ?string $causationId,
        public readonly string $origin,
        public readonly ?int $actorId,
        public readonly ?int $teamId,
        public readonly ?int $siteId,
        public readonly ?int $connectionId,
        public readonly ?string $locale,
        public readonly bool $dryRun = false,
        public readonly ?PublishIntent $publishIntent = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        $normalized = CanonicalIds::normalizeContextAttributes($attributes);

        $intentRaw = $normalized['publish_intent'] ?? null;
        $intent = null;
        if (is_string($intentRaw) && $intentRaw !== '') {
            $intent = PublishIntent::from($intentRaw);
        }

        return new self(
            executionId: (string) ($normalized['execution_id'] ?? Str::uuid()->toString()),
            correlationId: (string) ($normalized['correlation_id'] ?? Str::uuid()->toString()),
            causationId: isset($normalized['causation_id']) ? (string) $normalized['causation_id'] : null,
            origin: (string) ($normalized['origin'] ?? 'unknown'),
            actorId: CanonicalIds::nullableInt($normalized['actor_id'] ?? null),
            teamId: CanonicalIds::nullableInt($normalized['team_id'] ?? null),
            siteId: CanonicalIds::nullableInt($normalized['site_id'] ?? null),
            connectionId: CanonicalIds::nullableInt($normalized['connection_id'] ?? null),
            locale: isset($normalized['locale']) ? (string) $normalized['locale'] : null,
            dryRun: (bool) ($normalized['dry_run'] ?? false),
            publishIntent: $intent,
            metadata: is_array($normalized['metadata'] ?? null) ? $normalized['metadata'] : [],
        );
    }

    public function isWorkflowOrRuleOrigin(): bool
    {
        $origin = strtolower($this->origin);

        return str_starts_with($origin, 'workflow.')
            || str_starts_with($origin, 'rule.')
            || $origin === 'workflow'
            || $origin === 'rule';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'execution_id' => $this->executionId,
            'correlation_id' => $this->correlationId,
            'causation_id' => $this->causationId,
            'origin' => $this->origin,
            'actor_id' => $this->actorId,
            'team_id' => $this->teamId,
            'site_id' => $this->siteId,
            'connection_id' => $this->connectionId,
            'locale' => $this->locale,
            'dry_run' => $this->dryRun,
            'publish_intent' => $this->publishIntent?->value,
            'metadata' => $this->metadata,
        ];
    }
}
