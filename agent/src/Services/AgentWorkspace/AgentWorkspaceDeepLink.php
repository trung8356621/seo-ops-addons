<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace;

use Omnichannel\Addons\Agent\Filament\Pages\AgentWorkspacePage;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use Illuminate\Http\Request;

/**
 * Single source for Agent Workspace URLs.
 * Never invents an arbitrary connection_hash.
 */
final class AgentWorkspaceDeepLink
{
    public const MISSING_SITE_MESSAGE = 'Vui lòng chọn website trước khi mở Agent Workspace.';

    /**
     * @param  array{
     *     project_ref?: string|null,
     *     workspace_ref?: string|null,
     *     article_ref?: string|null,
     *     operation_ref?: string|null,
     *     conversation?: string|null,
     *     skill?: string|null,
     *     template?: string|null,
     *     connection_hash?: string|null
     * }  $query
     */
    public static function tryUrl(array $query = []): ?string
    {
        $hash = self::resolveConnectionHash($query['connection_hash'] ?? null);
        if ($hash === null) {
            return null;
        }

        SeoConnectionContext::applyUrlDefaults($hash);

        try {
            $url = AgentWorkspacePage::getUrl(
                parameters: ['connection_hash' => $hash],
                panel: 'seo',
            );
        } catch (\Throwable) {
            return null;
        }

        $params = self::filterParams([
            'tab' => 'agent',
            'project_ref' => $query['project_ref'] ?? null,
            'workspace_ref' => $query['workspace_ref'] ?? null,
            'article_ref' => $query['article_ref'] ?? null,
            'operation_ref' => $query['operation_ref'] ?? null,
            'conversation' => $query['conversation'] ?? null,
            'skill' => $query['skill'] ?? null,
            'template' => $query['template'] ?? null,
        ]);

        if ($params === []) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($params);
    }

    /**
     * @param  array{
     *     project_ref?: string|null,
     *     workspace_ref?: string|null,
     *     article_ref?: string|null,
     *     operation_ref?: string|null,
     *     conversation?: string|null,
     *     skill?: string|null,
     *     template?: string|null,
     *     connection_hash?: string|null
     * }  $query
     */
    public static function url(array $query = []): string
    {
        return self::tryUrl($query) ?? url('/seo');
    }

    /**
     * Build launcher URL from current request context (popup / Livewire pages).
     *
     * @return array{url: string|null, message: string|null, project_ref: string|null}
     */
    public static function forCurrentRequest(?Request $request = null): array
    {
        $request ??= request();
        $projectRef = self::inferProjectRef($request);
        $workspaceRef = self::nullableQuery($request, 'workspace_ref');
        $articleRef = self::inferArticleRef($request);

        $url = self::tryUrl([
            'project_ref' => $projectRef,
            'workspace_ref' => $workspaceRef,
            'article_ref' => $articleRef,
        ]);

        return [
            'url' => $url,
            'message' => $url === null ? self::MISSING_SITE_MESSAGE : null,
            'project_ref' => $projectRef,
        ];
    }

    public static function resolveConnectionHash(?string $explicit = null): ?string
    {
        if (is_string($explicit) && SeoConnectionContext::isValidHashFormat($explicit)) {
            return $explicit;
        }

        $fromContext = SeoConnectionContext::resolveHashFromRequest();
        if (is_string($fromContext) && SeoConnectionContext::isValidHashFormat($fromContext)) {
            return $fromContext;
        }

        $remembered = SeoConnectionContext::hash();
        if (is_string($remembered) && SeoConnectionContext::isValidHashFormat($remembered)) {
            return $remembered;
        }

        return null;
    }

    public static function inferProjectRef(?Request $request = null): ?string
    {
        $request ??= request();
        $fromQuery = self::nullableQuery($request, 'project_ref');
        if ($fromQuery !== null) {
            return $fromQuery;
        }

        $path = trim($request->path(), '/');
        if (preg_match('#(?:^|/)content-projects/(\d+)(?:/|$)#', $path, $m) === 1) {
            return ContentProjectPublicRef::project((int) $m[1]);
        }

        $projectId = SeoAccessControl::globalContentProjectId();
        if ($projectId !== null && $projectId > 0) {
            return ContentProjectPublicRef::project($projectId);
        }

        return null;
    }

    public static function inferArticleRef(?Request $request = null): ?string
    {
        $request ??= request();
        $fromQuery = self::nullableQuery($request, 'article_ref');
        if ($fromQuery !== null) {
            return $fromQuery;
        }

        $path = trim($request->path(), '/');
        if (preg_match('#(?:^|/)articles/(\d+)(?:/|$)#', $path, $m) === 1) {
            return ContentProjectPublicRef::article((int) $m[1]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     */
    private static function filterParams(array $params): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            if (! is_string($value)) {
                continue;
            }
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    private static function nullableQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
