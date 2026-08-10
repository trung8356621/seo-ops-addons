<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscPropertyStatus;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscOpportunity;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscPageMapping;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscProperty;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscQueryMapping;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscSyncRun;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommandHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\GscIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use InvalidArgumentException;
use RuntimeException;

abstract class AbstractGscIntelligenceHandler implements ContentProjectCommandHandler
{
    public function __construct(
        protected readonly KeywordIntelligenceTenantGuard $tenantGuard,
        protected readonly ContentProjectPreviewToken $previewToken,
    ) {}

    protected function resolveProperty(string $propertyRef): SeoGscProperty
    {
        $id = KeywordIntelligencePublicRef::resolveGscPropertyIdStrict($propertyRef);
        $property = SeoGscProperty::query()->find($id);

        if (! $property instanceof SeoGscProperty) {
            throw new RuntimeException('GSC property không tồn tại.');
        }

        return $property;
    }

    protected function resolveSyncRun(string $syncRunRef, ?SeoGscProperty $property = null): SeoGscSyncRun
    {
        $id = KeywordIntelligencePublicRef::resolveGscSyncRunIdStrict($syncRunRef);
        $syncRun = SeoGscSyncRun::query()->find($id);

        if (! $syncRun instanceof SeoGscSyncRun) {
            throw new RuntimeException('GSC sync run không tồn tại.');
        }

        if ($property !== null && (int) ($syncRun->property_id ?? 0) !== (int) $property->id) {
            throw new RuntimeException('GSC sync run không thuộc property.');
        }

        return $syncRun;
    }

    protected function resolveQueryMapping(string $mappingRef, ?SeoGscProperty $property = null): SeoGscQueryMapping
    {
        $id = KeywordIntelligencePublicRef::resolveGscQueryMappingIdStrict($mappingRef);
        $mapping = SeoGscQueryMapping::query()->find($id);

        if (! $mapping instanceof SeoGscQueryMapping) {
            throw new RuntimeException('GSC query mapping không tồn tại.');
        }

        if ($property !== null && (int) ($mapping->property_id ?? 0) !== (int) $property->id) {
            throw new RuntimeException('GSC query mapping không thuộc property.');
        }

        return $mapping;
    }

    protected function resolvePageMapping(string $mappingRef, ?SeoGscProperty $property = null): SeoGscPageMapping
    {
        $id = KeywordIntelligencePublicRef::resolveGscPageMappingIdStrict($mappingRef);
        $mapping = SeoGscPageMapping::query()->find($id);

        if (! $mapping instanceof SeoGscPageMapping) {
            throw new RuntimeException('GSC page mapping không tồn tại.');
        }

        if ($property !== null && (int) ($mapping->property_id ?? 0) !== (int) $property->id) {
            throw new RuntimeException('GSC page mapping không thuộc property.');
        }

        return $mapping;
    }

    protected function resolveOpportunity(string $opportunityRef, ?SeoGscProperty $property = null): SeoGscOpportunity
    {
        $id = KeywordIntelligencePublicRef::resolveGscOpportunityIdStrict($opportunityRef);
        $opportunity = SeoGscOpportunity::query()->find($id);

        if (! $opportunity instanceof SeoGscOpportunity) {
            throw new RuntimeException('GSC opportunity không tồn tại.');
        }

        if ($property !== null && (int) ($opportunity->property_id ?? 0) !== (int) $property->id) {
            throw new RuntimeException('GSC opportunity không thuộc property.');
        }

        return $opportunity;
    }

    protected function resolveWorkspace(string $workspaceRef): SeoKeywordWorkspace
    {
        $id = KeywordIntelligencePublicRef::resolveWorkspaceIdStrict($workspaceRef);
        $workspace = SeoKeywordWorkspace::query()->find($id);

        if (! $workspace instanceof SeoKeywordWorkspace) {
            throw new RuntimeException('Workspace không tồn tại.');
        }

        return $workspace;
    }

    protected function assertCanAccessProperty(SeoGscProperty $property, ActorContext $actor): void
    {
        $siteId = (int) ($property->site_id ?? 0);
        if ($siteId <= 0) {
            throw new RuntimeException('Property thiếu site_id.');
        }

        if ($actor->siteId !== null && $actor->siteId > 0 && $actor->siteId !== $siteId) {
            throw new RuntimeException('Property không thuộc site hiện tại.');
        }

        if (in_array($actor->actorType, ['user', 'api', 'agent'], true) && ! SeoAccessControl::canAccessSite($siteId)) {
            throw new RuntimeException('Không có quyền truy cập property.');
        }
    }

    protected function assertPropertyActive(SeoGscProperty $property): void
    {
        if ($property->archived_at !== null || $property->status === GscPropertyStatus::Archived) {
            throw new RuntimeException('Property archived.');
        }
    }

    /**
     * @param  callable(): ContentProjectActionResult  $callback
     */
    protected function wrap(callable $callback): ContentProjectActionResult
    {
        try {
            return $callback();
        } catch (InvalidArgumentException $e) {
            return ContentProjectActionResult::fail(
                GscIntelligenceActionCodes::VALIDATION_FAILED,
                $e->getMessage(),
            );
        } catch (RuntimeException $e) {
            return $this->mapRuntimeException($e);
        }
    }

    protected function requiresConfirmation(ActorContext $actor, ?string $token = null): bool
    {
        if (in_array($actor->actorType, ['api', 'agent'], true)) {
            return true;
        }

        return $token !== null && trim($token) !== '';
    }

    /**
     * @param  array<string, mixed>  $fingerprint
     */
    protected function assertConfirmationToken(?string $token, array $fingerprint, bool $required): ?ContentProjectActionResult
    {
        if (! $required) {
            return null;
        }

        if ($token === null || trim($token) === '') {
            return ContentProjectActionResult::fail(
                GscIntelligenceActionCodes::CONFIRMATION_REQUIRED,
                'Confirmation required.',
            );
        }

        $status = $this->previewToken->validate($token, $fingerprint);

        return match ($status) {
            'ok' => null,
            'expired' => ContentProjectActionResult::fail(
                GscIntelligenceActionCodes::CONFIRMATION_REQUIRED,
                'Confirmation token expired.',
            ),
            'stale' => ContentProjectActionResult::fail(
                GscIntelligenceActionCodes::CONFIRMATION_REQUIRED,
                'Confirmation token stale — preview outdated.',
            ),
            default => ContentProjectActionResult::fail(
                GscIntelligenceActionCodes::CONFIRMATION_REQUIRED,
                'Invalid confirmation token.',
            ),
        };
    }

    protected function consumeConfirmationToken(?string $token): void
    {
        if ($token !== null && trim($token) !== '') {
            $this->previewToken->consume($token);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function buildFingerprint(string $action, int $propertyId, array $extra = []): array
    {
        return array_merge([
            'action' => $action,
            'property_id' => $propertyId,
        ], $extra);
    }

    protected function mapRuntimeException(RuntimeException $e): ContentProjectActionResult
    {
        $message = $e->getMessage();

        if (str_contains($message, 'Không có quyền') || str_contains($message, 'không thuộc site')) {
            return ContentProjectActionResult::fail(GscIntelligenceActionCodes::FORBIDDEN, $message);
        }

        if (str_contains($message, 'không tồn tại')) {
            return ContentProjectActionResult::fail(GscIntelligenceActionCodes::NOT_FOUND, $message);
        }

        if (str_contains($message, 'archived') || str_contains($message, 'Archived')) {
            return ContentProjectActionResult::fail(GscIntelligenceActionCodes::PROPERTY_ARCHIVED_STATE, $message);
        }

        if (str_starts_with($message, 'gsc.')) {
            return ContentProjectActionResult::fail($message, $message);
        }

        return ContentProjectActionResult::fail(GscIntelligenceActionCodes::FAILED, $message);
    }
}
