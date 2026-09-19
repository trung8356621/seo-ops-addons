<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ContentProjectPublishingQueue;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueReadModel;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectQueueHealthService;
use Omnichannel\Addons\Publishing\Filament\Pages\PublishingQueueHub;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\ProjectRoot;

/**
 * Regression: domain-neutral execution projects (site_id null) must keep Publishing Queue project scope.
 */
final class PublishingQueueDomainNeutralProjectScopeContractTest extends TestCase
{
    public function test_resolve_project_uses_workspace_access_not_can_access_site_zero(): void
    {
        $method = new ReflectionMethod(PublishingQueueHub::class, 'resolveProject');
        $src = $this->methodSource($method);

        self::assertStringContainsString('canAccessPublishingQueueProject', $src);
        self::assertStringNotContainsString('canAccessSite((int) ($project->site_id ?? 0))', $src);
        self::assertStringContainsString('$this->projectId = $requestedProjectId', $src);
    }

    public function test_can_access_publishing_queue_reuses_can_view_and_tenant_guard(): void
    {
        $method = new ReflectionMethod(SeoProjectResource::class, 'canAccessPublishingQueueProject');
        $src = $this->methodSource($method);

        self::assertStringContainsString('canView($project)', $src);
        self::assertStringContainsString('domainNeutralProjectHasAccessibleItemOwnership', $src);
        self::assertStringContainsString('accessibleSiteIds()', $src);
        self::assertStringNotContainsString('canAccessSite((int) ($project->site_id ?? 0))', $src);
    }

    public function test_tenant_guard_resolves_item_site_ids_never_zero(): void
    {
        $guard = (string) file_get_contents(
            (new ReflectionClass(ContentProjectTenantGuard::class))->getFileName(),
        );

        self::assertStringContainsString('function resolveProjectItemSiteIds', $guard);
        self::assertStringContainsString('function resolveProjectQueueHealthSiteIds', $guard);
        self::assertStringContainsString('function domainNeutralProjectHasAccessibleItemOwnership', $guard);
        self::assertStringContainsString("where('site_id', '>', 0)", $guard);

        $healthResolver = $this->methodSource(
            new ReflectionMethod(ContentProjectTenantGuard::class, 'resolveProjectQueueHealthSiteIds'),
        );
        self::assertStringContainsString('if ($projectSiteId > 0)', $healthResolver);
        self::assertStringContainsString('resolveProjectItemSiteIds', $healthResolver);
        self::assertStringNotContainsString('return [0]', $healthResolver);
        self::assertStringNotContainsString('[(int) ($project->site_id ?? 0)]', $healthResolver);
    }

    public function test_queue_health_passes_project_id_and_resolved_sites(): void
    {
        $method = new ReflectionMethod(PublishingQueueHub::class, 'getQueueHealthProperty');
        $src = $this->methodSource($method);

        self::assertStringContainsString('resolveProjectQueueHealthSiteIds', $src);
        self::assertStringContainsString('->snapshot($siteIds, $connectionId, $projectId)', $src);
        self::assertStringNotContainsString('[(int) ($this->project->site_id ?? 0)]', $src);
    }

    public function test_queue_health_service_scopes_by_project_id(): void
    {
        $method = new ReflectionMethod(ContentProjectQueueHealthService::class, 'snapshot');
        $src = $this->methodSource($method);

        self::assertStringContainsString('?int $projectId = null', $src);
        self::assertStringContainsString('$scopedProjectId', $src);
        self::assertStringContainsString('whereKey($scopedProjectId)', $src);
        self::assertStringContainsString('if ($intId > 0)', $src);
    }

    public function test_queue_payload_calls_for_hub_with_resolved_project_id(): void
    {
        $method = new ReflectionMethod(PublishingQueueHub::class, 'getQueuePayloadProperty');
        $src = $this->methodSource($method);

        self::assertStringContainsString('forHub(', $src);
        self::assertStringContainsString('(int) $this->project->getKey()', $src);
    }

    public function test_for_hub_requires_publishing_queue_access_before_for_project(): void
    {
        $method = new ReflectionMethod(ContentProjectPublishingQueueReadModel::class, 'forHub');
        $src = $this->methodSource($method);

        self::assertStringContainsString('canAccessPublishingQueueProject($project)', $src);
        self::assertStringContainsString('forProject($project', $src);
    }

    public function test_for_project_filters_domain_neutral_rows_to_accessible_sites(): void
    {
        $method = new ReflectionMethod(ContentProjectPublishingQueueReadModel::class, 'forProject');
        $src = $this->methodSource($method);

        self::assertStringContainsString('(int) ($project->site_id ?? 0) <= 0', $src);
        self::assertStringContainsString('accessibleSiteIds()', $src);
        self::assertStringContainsString('whereIn(\'site_id\', $accessibleSiteIds)', $src);
        self::assertStringContainsString('shouldScopeToAccountOwner()', $src);
    }

    public function test_legacy_nested_redirect_keeps_project_scope_url(): void
    {
        $page = (string) file_get_contents(
            (new ReflectionClass(ContentProjectPublishingQueue::class))->getFileName(),
        );

        self::assertStringContainsString('canAccessPublishingQueueProject($project)', $page);
        self::assertStringContainsString('getPublishingQueueUrl($project)', $page);
        self::assertStringNotContainsString('canAccessSite((int) ($project->site_id ?? 0))', $page);

        $urlMethod = $this->methodSource(
            new ReflectionMethod(SeoProjectResource::class, 'getPublishingQueueUrl'),
        );
        self::assertStringContainsString("PublishingQueueHub::getUrl(['projectId' => (int) \$project->getKey()])", $urlMethod);
    }

    public function test_load_accessible_project_for_tasks_uses_same_access_helper(): void
    {
        $method = new ReflectionMethod(PublishingQueueHub::class, 'loadAccessibleProjectForTasks');
        $src = $this->methodSource($method);

        self::assertStringContainsString('canAccessPublishingQueueProject($project)', $src);
        self::assertStringNotContainsString('canAccessSite((int) ($project->site_id ?? 0))', $src);
    }

    public function test_hub_file_does_not_clear_project_id_solely_for_null_site_id(): void
    {
        $hub = (string) file_get_contents(
            ProjectRoot::addonsPath().'/publishing/src/Filament/Pages/PublishingQueueHub.php',
        );

        // Regression: must not gate resolve on canAccessSite(project.site_id ?? 0).
        self::assertStringNotContainsString(
            'canAccessSite((int) ($project->site_id ?? 0))',
            $hub,
        );
        self::assertStringContainsString('canAccessPublishingQueueProject', $hub);
    }

    private function methodSource(ReflectionMethod $method): string
    {
        $file = (string) $method->getFileName();
        $lines = file($file);
        self::assertNotFalse($lines);

        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();

        return implode('', array_slice($lines, $start, $end - $start));
    }
}
