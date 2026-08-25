<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Notifications;

use Omnichannel\Addons\Seo\Support\SeoConnectionContext;

final class OperationalNotificationDeepLinks
{
    public function contentProject(int $projectId, ?string $tab = null, array $query = []): string
    {
        $path = 'content-projects/'.$projectId;
        if ($tab !== null && $tab !== '') {
            $query['tab'] = $tab;
        }

        return $this->panel($path, $query);
    }

    public function contentProjectNeedsReview(int $projectId): string
    {
        return $this->contentProject($projectId, null, [
            'workflow' => 'recently_completed',
        ]);
    }

    public function contentProjectFailed(int $projectId): string
    {
        return $this->contentProject($projectId, null, [
            'workflow' => 'failed',
        ]);
    }

    public function promptEdit(int $promptId): string
    {
        return $this->panel('prompts/'.$promptId.'/edit');
    }

    public function publishingQueue(?int $projectId = null, ?string $status = null): string
    {
        $query = [];
        if ($projectId !== null && $projectId > 0) {
            $query['projectId'] = $projectId;
        }
        if ($status !== null && $status !== '') {
            $query['state'] = $status;
        }

        return $this->panel('publishing-queue', $query);
    }

    public function operationsCenter(?int $operationId = null): string
    {
        $query = [];
        if ($operationId !== null && $operationId > 0) {
            $query['operation_id'] = $operationId;
        }

        return $this->panel('content-operations', $query);
    }

    public function siteSyncRun(int $runId): string
    {
        return $this->panel('site-sync-operations', ['run_id' => $runId]);
    }

    public function domainConnection(int $domainId): string
    {
        return $this->panel('domains/'.$domainId.'/edit');
    }

    public function aiCenterResilience(): string
    {
        return $this->panel('settings/ai-center', ['tab' => 'resilience']);
    }

    public function aiCenterHealth(): string
    {
        return $this->panel('settings/ai-center', ['tab' => 'health']);
    }

    public function aiConnectionEdit(int $connectionId): string
    {
        return $this->panel('ai-connections/'.$connectionId.'/edit');
    }

    public function articleIndexHealth(?int $articleId = null, ?int $siteId = null): string
    {
        $query = ['tab' => 'needs_review'];
        if ($siteId !== null && $siteId > 0) {
            $query['site'] = $siteId;
        }
        if ($articleId !== null && $articleId > 0) {
            $query['focus'] = $articleId;
        }

        return $this->panel('articles/index-health', $query);
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    public function panel(string $path, array $query = []): string
    {
        $url = SeoConnectionContext::panelUrl(ltrim($path, '/'));
        $filtered = [];
        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $filtered[$key] = $value;
        }

        if ($filtered === []) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($filtered);
    }
}
