<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceHealth;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceResolver;

final class SocialAutomationBootstrapController extends Controller
{
    public function __construct(
        private readonly SocialAutomationAccess $access,
        private readonly SocialAutomationServiceHealth $health,
        private readonly SocialAutomationServiceResolver $resolver,
    ) {}

    public function __invoke(): JsonResponse
    {
        $this->access->assertCanAccess();

        if (! $this->resolver->isActive()) {
            return response()->json([
                'ok' => false,
                'message' => 'Social Automation service is inactive',
                'bootstrap' => $this->health->bootstrap(),
            ], 403);
        }

        return response()->json([
            'ok' => true,
            'bootstrap' => $this->health->bootstrap(),
        ]);
    }
}
