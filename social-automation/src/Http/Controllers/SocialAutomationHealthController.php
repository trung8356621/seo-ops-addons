<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationAccess;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceHealth;

final class SocialAutomationHealthController extends Controller
{
    public function __construct(
        private readonly SocialAutomationAccess $access,
        private readonly SocialAutomationServiceHealth $health,
    ) {}

    public function __invoke(): JsonResponse
    {
        $this->access->assertCanAccess();

        $report = $this->health->report();

        return response()->json($report, ($report['ok'] ?? false) ? 200 : 503);
    }
}
