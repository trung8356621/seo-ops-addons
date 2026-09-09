<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\Seeding\Services\SeedingSharedTopicService;
use Omnichannel\Addons\Seeding\Support\SeedingAccess;

final class SeedingFeedController extends Controller
{
    public function __construct(
        private readonly SeedingSharedTopicService $topics,
        private readonly SeedingAccess $access,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->access->assertCanAccess();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $userId = (int) $user->id;

        return response()->json([
            'ok' => true,
            'topics' => $this->topics->eligibleFeedForUser($userId),
            'link_usage_today' => $this->topics->linkUsageTodayForUser($userId),
            'settings' => [
                'max_comments_per_day' => $this->topics->maxCommentsPerDay(),
                'active_member_count' => $this->topics->activeMemberCount(),
            ],
        ]);
    }
}
