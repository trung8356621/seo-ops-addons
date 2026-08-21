<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Http\Controllers;

use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class SeoPanelRedirectController extends Controller
{
    public function __invoke(Request $request, SeoDatabaseConnectionService $databaseConnection): RedirectResponse|View
    {
        if (! SeoAccessControl::canAccessSeoPanel()) {
            if ($databaseConnection->resolveRedirectHash() === null) {
                return view('seo-content-ai::pages.no-seo-workspace');
            }

            return redirect()->to(\Omnichannel\Addons\Seo\Support\SeoPanelRoutes::shortLoginUrl());
        }

        $user = auth()->user();
        if ($user !== null) {
            $mainHash = app(\Omnichannel\Addons\Seo\Services\SeoLoginServiceResolver::class)
                ->resolveMainConnectionHashForUser($user);

            if ($mainHash !== null) {
                \Omnichannel\Addons\Seo\Support\SeoConnectionContext::rememberHash($mainHash);

                // Main Service short entry (real /seo/... routes, no URI rewrite).
                return redirect('/seo/content-operations');
            }

            // Multi-service without Main — show picker / first explicit hash workspace.
            return view('seo-content-ai::pages.no-seo-workspace');
        }

        return view('seo-content-ai::pages.no-seo-workspace');
    }
}
