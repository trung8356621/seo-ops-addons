<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Handlers;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordWorkspaceStatus;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordWorkspace;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\CreateKeywordWorkspaceCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Quotas\KeywordIntelligenceQuotaGuard;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateKeywordWorkspaceHandler extends AbstractKeywordIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly KeywordIntelligenceQuotaGuard $quota,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof CreateKeywordWorkspaceCommand) {
            throw new InvalidArgumentException('Expected CreateKeywordWorkspaceCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $attrs = $command->attributes;
            $siteId = (int) ($attrs['site_id'] ?? $actor->siteId ?? 0);

            if ($siteId <= 0) {
                return ContentProjectActionResult::fail(KeywordIntelligenceActionCodes::VALIDATION_FAILED, 'site_id is required.');
            }

            if ($actor->siteId !== null && $actor->siteId > 0 && $actor->siteId !== $siteId) {
                return ContentProjectActionResult::fail(KeywordIntelligenceActionCodes::FORBIDDEN, 'Cannot create workspace for another site.');
            }

            if (in_array($actor->actorType, ['user', 'api', 'agent'], true) && ! SeoAccessControl::canAccessSite($siteId)) {
                return ContentProjectActionResult::fail(KeywordIntelligenceActionCodes::FORBIDDEN, 'No access to site.');
            }

            $name = trim((string) ($attrs['name'] ?? ''));
            if ($name === '') {
                return ContentProjectActionResult::fail(KeywordIntelligenceActionCodes::VALIDATION_FAILED, 'name is required.');
            }

            if (! $this->quota->canCreateWorkspace($siteId)) {
                return ContentProjectActionResult::fail(KeywordIntelligenceActionCodes::WORKSPACE_LIMIT_EXCEEDED, 'Workspace limit exceeded for site.');
            }

            $workspace = DB::connection('omi_seo_ai')->transaction(function () use ($attrs, $siteId, $name, $actor): SeoKeywordWorkspace {
                $workspace = new SeoKeywordWorkspace([
                    'public_ref' => 'pending',
                    'tenant_id' => $attrs['tenant_id'] ?? null,
                    'site_id' => $siteId,
                    'name' => $name,
                    'description' => $attrs['description'] ?? null,
                    'status' => KeywordWorkspaceStatus::Draft->value,
                    'clustering_strategy' => $attrs['strategy'] ?? null,
                    'language' => $attrs['language'] ?? null,
                    'country' => $attrs['country'] ?? null,
                    'created_by' => $actor->actorId,
                ]);
                $workspace->save();
                $workspace->public_ref = KeywordIntelligencePublicRef::workspace((int) $workspace->id);
                $workspace->save();

                return $workspace;
            });

            return ContentProjectActionResult::ok(
                KeywordIntelligenceActionCodes::WORKSPACE_CREATED,
                'Workspace created.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'site_id' => $siteId,
                ],
            );
        });
    }
}
