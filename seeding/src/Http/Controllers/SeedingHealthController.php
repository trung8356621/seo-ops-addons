<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Omnichannel\Addons\Seeding\Support\SeedingAccess;
use Omnichannel\Addons\Seeding\Support\SeedingServiceHealth;

final class SeedingHealthController extends Controller
{
    public function __construct(
        private readonly SeedingAccess $access,
        private readonly SeedingServiceHealth $health,
    ) {}

    public function __invoke(): JsonResponse
    {
        $this->access->assertCanAccess();

        $report = $this->health->report();

        return response()->json($report, ($report['ok'] ?? false) ? 200 : 503);
    }
}
