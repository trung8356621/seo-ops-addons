<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Tools;

use App\Api\Services\ServiceApiContext;
use InvalidArgumentException;

/**
 * Neutral tool execution context.
 *
 * Decouples tool execution from HTTP transport or specific credential models.
 * May be adapted from ServiceApiContext or later from Agent Runtime user session.
 */
final class SeoToolContext
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public readonly string $actorRef,
        public readonly string $actorType = 'service',
        public readonly ?int $actorId = null,
        public readonly string $serviceSlug = 'seo',
        public readonly ?string $siteRef = null,
        public readonly ?int $resolvedSiteId = null,
        public readonly ?int $resolvedActorUserId = null,
        public readonly array $scopes = [],
        public readonly string $requestRef = '',
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $tenantRef = null,
    ) {}

    public static function fromServiceApiContext(
        ServiceApiContext $context,
        ?string $siteRef = null,
        ?int $resolvedSiteId = null,
        string $requestRef = '',
        ?string $idempotencyKey = null,
    ): self {
        $parsedSiteId = $resolvedSiteId;
        if ($parsedSiteId === null && $siteRef !== null) {
            $parsedSiteId = self::parseSiteId($siteRef);
        }

        $normSiteRef = $siteRef;
        if ($normSiteRef === null && $parsedSiteId !== null && $parsedSiteId > 0) {
            $normSiteRef = 'site:'.$parsedSiteId;
        }

        return new self(
            actorRef: 'service_credential:'.$context->credentialId(),
            actorType: 'service',
            actorId: $context->credentialId(),
            serviceSlug: $context->serviceSlug(),
            siteRef: $normSiteRef,
            resolvedSiteId: $parsedSiteId,
            resolvedActorUserId: null,
            scopes: $context->scopes(),
            requestRef: $requestRef !== '' ? $requestRef : bin2hex(random_bytes(8)),
            idempotencyKey: $idempotencyKey,
            tenantRef: $parsedSiteId !== null && $parsedSiteId > 0 ? 'site:'.$parsedSiteId : null,
        );
    }

    public function withResolvedSiteId(int $siteId): self
    {
        return new self(
            actorRef: $this->actorRef,
            actorType: $this->actorType,
            actorId: $this->actorId,
            serviceSlug: $this->serviceSlug,
            siteRef: 'site:'.$siteId,
            resolvedSiteId: $siteId,
            resolvedActorUserId: $this->resolvedActorUserId,
            scopes: $this->scopes,
            requestRef: $this->requestRef,
            idempotencyKey: $this->idempotencyKey,
            tenantRef: $this->tenantRef ?? ('site:'.$siteId),
        );
    }

    public function hasScope(string $requiredScope): bool
    {
        $requiredScope = trim($requiredScope);
        if ($requiredScope === '') {
            return false;
        }

        foreach ($this->scopes as $scope) {
            $scope = trim((string) $scope);
            if ($scope === '*' || $scope === $requiredScope) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $requiredScopes
     */
    public function hasAnyScope(array $requiredScopes): bool
    {
        if (empty($requiredScopes)) {
            return true;
        }

        foreach ($requiredScopes as $requiredScope) {
            if ($this->hasScope($requiredScope)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $requiredScopes
     */
    public function satisfiesAllScopes(array $requiredScopes): bool
    {
        foreach ($requiredScopes as $requiredScope) {
            if (! $this->hasScope($requiredScope)) {
                return false;
            }
        }

        return true;
    }

    public static function parseSiteId(?string $siteRef): ?int
    {
        if ($siteRef === null) {
            return null;
        }
        $siteRef = trim($siteRef);
        if ($siteRef === '') {
            return null;
        }
        if (preg_match('/^site:(\d+)$/i', $siteRef, $m) === 1) {
            return max(1, (int) $m[1]);
        }
        if (ctype_digit($siteRef)) {
            return max(1, (int) $siteRef);
        }

        return null;
    }
}

