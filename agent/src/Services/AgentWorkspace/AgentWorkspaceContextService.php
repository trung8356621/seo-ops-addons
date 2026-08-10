<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use App\Models\Site;
use App\Models\User;
use InvalidArgumentException;
use RuntimeException;

/**
 * Builds fail-closed Agent Workspace context from public refs + auth.
 */
final class AgentWorkspaceContextService
{
    /**
     * @param  array{
     *     project_ref?: string|null,
     *     workspace_ref?: string|null,
     *     article_ref?: string|null,
     *     operation_ref?: string|null,
     *     site_id?: int|null
     * }  $query
     */
    public function fromAuthenticatedUser(User $user, array $query = []): AgentWorkspaceContext
    {
        $siteId = isset($query['site_id']) ? (int) $query['site_id'] : (int) (SeoAccessControl::globalSiteId() ?? 0);
        if ($siteId <= 0) {
            throw new RuntimeException('agent.context.site_required');
        }

        if (! SeoAccessControl::canAccessSite($siteId)) {
            throw new RuntimeException('agent.context.site_denied');
        }

        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            throw new RuntimeException('agent.context.site_not_found');
        }

        $siteRef = ContentProjectPublicRef::site($siteId);
        $tenantRef = 'tenant:'.$siteRef;
        $connection = SeoConnectionContext::current();

        $projectRef = $this->nullableString($query['project_ref'] ?? null);
        $workspaceRef = $this->nullableString($query['workspace_ref'] ?? null);
        $articleRef = $this->nullableString($query['article_ref'] ?? null);
        $operationRef = $this->nullableString($query['operation_ref'] ?? null);

        // Cross-site public refs are rejected fail-closed (best-effort decode).
        $this->assertProjectBelongsToSite($projectRef, $siteId);
        $this->assertArticleBelongsToSite($articleRef, $siteId);

        $scopes = $this->scopesForUser($user);

        return new AgentWorkspaceContext(
            tenantRef: $tenantRef,
            siteRef: $siteRef,
            tenantId: (int) $site->user_id,
            siteId: $siteId,
            connectionId: $connection?->id,
            siteName: (string) ($site->domain ?: ('Site #'.$siteId)),
            actorRef: 'user:'.(int) $user->id,
            actorUserId: (int) $user->id,
            role: SeoAccessControl::actualRole(),
            scopes: $scopes,
            projectRef: $projectRef,
            workspaceRef: $workspaceRef,
            articleRef: $articleRef,
            operationRef: $operationRef,
            providers: [
                'serp' => $this->serpConfigured($siteId),
            ],
        );
    }

    /**
     * Web-session Agent scopes from RBAC (not Sanctum PAT abilities).
     *
     * @return list<string>
     */
    public function scopesForAuthenticatedUser(User $user): array
    {
        return $this->scopesForUser($user);
    }

    /**
     * @return list<string>
     */
    private function scopesForUser(User $user): array
    {
        $scopes = ['content-project:read'];

        if (SeoAccessControl::canMutateContentProjects()) {
            $scopes[] = 'content-project:write';
            $scopes[] = 'content-project:generate';
            $scopes[] = 'content-project:review';
            $scopes[] = 'content-project:schedule';
        }

        if (SeoAccessControl::canSyncArticlesToWordPress() || SeoAccessControl::canAccessManagerFeatures()) {
            $scopes[] = 'content-project:publish';
        }

        if (SeoAccessControl::canArchiveContentProjects()) {
            $scopes[] = 'content-project:archive';
        }

        if (SeoAccessControl::canAccessManagerFeatures()) {
            $scopes[] = 'content-project:admin';
        }

        return array_values(array_unique($scopes));
    }

    private function assertProjectBelongsToSite(?string $projectRef, int $siteId): void
    {
        if ($projectRef === null) {
            return;
        }

        try {
            $projectId = ContentProjectPublicRef::resolveProjectIdStrict($projectRef);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException('agent.context.project_invalid', 0, $e);
        }

        $project = \Omnichannel\Addons\ContentProjects\Models\SeoProject::query()->find($projectId);
        if ($project === null || (int) $project->site_id !== $siteId) {
            throw new RuntimeException('agent.context.project_site_mismatch');
        }
    }

    private function assertArticleBelongsToSite(?string $articleRef, int $siteId): void
    {
        if ($articleRef === null) {
            return;
        }

        try {
            if (! str_starts_with($articleRef, 'cpa_')) {
                throw new InvalidArgumentException('Invalid article ref.');
            }
            $articleId = ContentProjectPublicRef::decodeArticle($articleRef);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException('agent.context.article_invalid', 0, $e);
        }

        $article = \Omnichannel\Addons\Content\Models\SeoArticle::query()->find($articleId);
        if ($article === null || (int) $article->site_id !== $siteId) {
            throw new RuntimeException('agent.context.article_site_mismatch');
        }
    }

    private function serpConfigured(int $siteId): bool
    {
        try {
            $site = Site::query()->find($siteId);
            if (! $site instanceof Site) {
                return false;
            }

            return \Omnichannel\Addons\SearchIntelligence\Models\SeoSerpProviderConnection::query()
                ->where(function ($q) use ($site): void {
                    $q->where('user_id', (int) $site->user_id)
                        ->orWhere('is_global', true);
                })
                ->where('status', 'active')
                ->get()
                ->contains(static fn ($row): bool => $row->isConfigured());
        } catch (\Throwable) {
            return false;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
