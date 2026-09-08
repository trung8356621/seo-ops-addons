<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Resolve which model History should display for an AI execution.
 *
 * Display chain (never silent requested/default fallback):
 *   actual_provider_model → candidate_model → Unknown model
 */
final class AiExecutionModelAttribution
{
    public const SOURCE_PROVIDER_RESPONSE = 'provider_response';

    public const SOURCE_ROUTING_CANDIDATE = 'routing_candidate';

    public const SOURCE_UNKNOWN = 'unknown';

    public function __construct(
        public readonly ?string $requestedModel,
        public readonly ?string $candidateModel,
        public readonly ?string $actualProviderModel,
        public readonly string $modelSource,
        public readonly ?string $provider,
        public readonly ?int $connectionId,
        public readonly ?bool $isFreeCandidate,
        public readonly ?int $attempt,
        public readonly ?string $status,
    ) {}

    /**
     * Build attribution at provider-boundary success/failure.
     *
     * @param  array<string, mixed>  $usage
     */
    public static function fromProviderAttempt(
        ?string $requestedModel,
        string $candidateModel,
        bool $isFreeCandidate,
        string $provider,
        ?int $connectionId,
        ?array $usage,
        ?int $attempt = null,
        ?string $status = null,
    ): self {
        $actual = self::trimOrNull(
            is_array($usage)
                ? ($usage['resolved_model'] ?? $usage['actual_provider_model'] ?? null)
                : null,
        );
        if ($actual === null && is_array($usage['routing'] ?? null)) {
            $actual = self::trimOrNull($usage['routing']['actual_provider_model'] ?? null);
        }

        return new self(
            requestedModel: self::trimOrNull($requestedModel),
            candidateModel: self::trimOrNull($candidateModel),
            actualProviderModel: $actual,
            modelSource: $actual !== null
                ? self::SOURCE_PROVIDER_RESPONSE
                : (self::trimOrNull($candidateModel) !== null
                    ? self::SOURCE_ROUTING_CANDIDATE
                    : self::SOURCE_UNKNOWN),
            provider: self::trimOrNull($provider),
            connectionId: $connectionId,
            isFreeCandidate: $isFreeCandidate,
            attempt: $attempt,
            status: self::trimOrNull($status),
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $tokenUsage
     */
    public static function fromPersistence(array $snapshot, array $tokenUsage = []): self
    {
        $routing = is_array($tokenUsage['routing'] ?? null) ? $tokenUsage['routing'] : [];
        $requested = self::trimOrNull(
            $snapshot['requested_model']
            ?? $tokenUsage['requested_model']
            ?? $routing['requested_model']
            ?? null,
        );
        $candidate = self::trimOrNull(
            $snapshot['candidate_model']
            ?? $routing['model']
            ?? $routing['candidate_model']
            ?? null,
        );
        $actual = self::trimOrNull(
            $snapshot['actual_provider_model']
            ?? $tokenUsage['resolved_model']
            ?? $routing['actual_provider_model']
            ?? null,
        );

        // Legacy rows: raw_model_used often equals successful candidate — treat as candidate, not requested.
        if ($candidate === null) {
            $legacy = self::trimOrNull($snapshot['raw_model_used'] ?? $snapshot['planner_model'] ?? null);
            if ($legacy !== null) {
                $candidate = $legacy;
            }
        }

        $isFree = $snapshot['is_free_candidate'] ?? $routing['is_free'] ?? $routing['free'] ?? null;
        if (! is_bool($isFree)) {
            $isFree = null;
        }

        $modelSource = self::trimOrNull($snapshot['model_source'] ?? null);
        if ($modelSource === null) {
            if ($actual !== null) {
                $modelSource = self::SOURCE_PROVIDER_RESPONSE;
            } elseif ($candidate !== null) {
                $modelSource = self::SOURCE_ROUTING_CANDIDATE;
            } else {
                $modelSource = self::SOURCE_UNKNOWN;
            }
        }

        $connectionId = $snapshot['connection_id'] ?? $routing['connection_id'] ?? null;
        $connectionId = is_numeric($connectionId) ? (int) $connectionId : null;

        $attempt = $snapshot['attempt'] ?? $routing['attempt'] ?? $routing['attempt_number'] ?? null;
        $attempt = is_numeric($attempt) ? (int) $attempt : null;

        return new self(
            requestedModel: $requested,
            candidateModel: $candidate,
            actualProviderModel: $actual,
            modelSource: $modelSource,
            provider: self::trimOrNull($snapshot['provider'] ?? $routing['provider'] ?? null),
            connectionId: $connectionId,
            isFreeCandidate: $isFree,
            attempt: $attempt,
            status: self::trimOrNull($snapshot['status'] ?? null),
        );
    }

    /**
     * Model shown on History cards — never requested/default.
     */
    public function displayModel(): string
    {
        if ($this->actualProviderModel !== null) {
            return $this->actualProviderModel;
        }
        if ($this->candidateModel !== null) {
            return $this->candidateModel;
        }

        return 'Unknown model';
    }

    public function displayModelSource(): string
    {
        if ($this->actualProviderModel !== null) {
            return self::SOURCE_PROVIDER_RESPONSE;
        }
        if ($this->candidateModel !== null) {
            return self::SOURCE_ROUTING_CANDIDATE;
        }

        return self::SOURCE_UNKNOWN;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSnapshotFields(): array
    {
        return array_filter([
            'requested_model' => $this->requestedModel,
            'candidate_model' => $this->candidateModel,
            'actual_provider_model' => $this->actualProviderModel,
            'model_source' => $this->displayModelSource(),
            'provider' => $this->provider,
            'connection_id' => $this->connectionId,
            'is_free_candidate' => $this->isFreeCandidate,
            'attempt' => $this->attempt,
            // Keep legacy keys for older readers, but equal to display model only.
            'raw_model_used' => $this->displayModel() !== 'Unknown model' ? $this->displayModel() : null,
            'planner_model' => $this->displayModel() !== 'Unknown model' ? $this->displayModel() : null,
        ], static fn (mixed $v): bool => $v !== null && $v !== '');
    }

    private static function trimOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
