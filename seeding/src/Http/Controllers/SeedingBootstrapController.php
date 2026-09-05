<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Omnichannel\Addons\Seeding\Support\SeedingAccess;
use Omnichannel\Addons\Seeding\Support\SeedingServiceHealth;
use Omnichannel\Addons\Seeding\Support\SeedingServiceResolver;

final class SeedingBootstrapController extends Controller
{
    public function __construct(
        private readonly SeedingAccess $access,
        private readonly SeedingServiceHealth $health,
        private readonly SeedingServiceResolver $resolver,
    ) {}

    public function __invoke(): JsonResponse
    {
        $this->access->assertCanAccess();

        if (! $this->resolver->isActive()) {
            return response()->json([
                'ok' => false,
                'message' => 'Seeding service is inactive',
                'bootstrap' => $this->health->bootstrap(),
            ], 403);
        }

        return response()->json([
            'ok' => true,
            'bootstrap' => $this->health->bootstrap(),
        ]);
    }
}
