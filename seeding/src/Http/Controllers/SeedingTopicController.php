<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Retired experimental site-scoped topic CRUD (omi_seo_ai era).
 */
final class SeedingTopicController extends Controller
{
    public function gone(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => 'Legacy Seeding topic CRUD đã ngưng. Dùng /api/seeding/feed, /api/seeding/topics/share, /api/seeding/reports.',
        ], 410);
    }
}
