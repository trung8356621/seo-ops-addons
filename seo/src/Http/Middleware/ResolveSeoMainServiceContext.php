<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seo\Services\SeoLoginServiceResolver;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Omnichannel\Addons\Seo\Support\SeoPanelRoutes;

/**
 * Short /seo/* panel: resolve Main/default connection into the same SEO context
 * used by hash routes — without rewriting REQUEST_URI.
 */
final class ResolveSeoMainServiceContext
{
    public function __construct(
        private readonly SeoDatabaseConnectionService $databaseConnection,
        private readonly SeoLoginServiceResolver $loginServiceResolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldSkip($request)) {
            return $next($request);
        }

        if (! auth()->check()) {
            return redirect()->to(SeoPanelRoutes::shortLoginUrl([
                'return_url' => $request->fullUrl(),
            ]));
        }

        /** @var User $user */
        $user = auth()->user();

        $hash = SeoConnectionContext::hash();
        if ($hash === null || ! SeoConnectionContext::isValidHashFormat($hash)) {
            $hash = $this->loginServiceResolver->resolveMainConnectionHashForUser($user);
        }

        if ($hash === null || ! SeoConnectionContext::isValidHashFormat($hash)) {
            return redirect()->route('seo.panel.redirect');
        }

        try {
            $connection = $this->databaseConnection->bootstrapByHash($hash);
        } catch (RuntimeException) {
            return redirect()->route('seo.panel.redirect');
        }

        if (! $this->databaseConnection->userCanAccessConnection($user, $connection)) {
            abort(403, 'Tài khoản của bạn không có quyền truy cập vào không gian lưu trữ SEO này.');
        }

        SeoConnectionContext::rememberHash($hash);

        return $next($request);
    }

    private function shouldSkip(Request $request): bool
    {
        $path = trim($request->path(), '/');

        return $path === 'seo/login'
            || $path === 'seo/logout'
            || str_starts_with($path, 'seo/oauth/');
    }
}
