<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Omnichannel\Addons\Seeding\Services\SeedingReportService;
use Omnichannel\Addons\Seeding\Services\SeedingSharedTopicService;
use Omnichannel\Addons\Seeding\Support\SeedingAccess;
use Omnichannel\Addons\Seeding\Support\SeedingTopicPresenter;
use Throwable;

final class SeedingReportController extends Controller
{
    public function __construct(
        private readonly SeedingReportService $reports,
        private readonly SeedingSharedTopicService $topics,
        private readonly SeedingAccess $access,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->access->assertCanMutate();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'topic_id' => ['required', 'integer', 'min:1'],
            'comment_text' => ['required', 'string', 'max:20000'],
            'seed_link_id' => ['nullable', 'string', 'max:64'],
            'seed_url' => ['nullable', 'string', 'max:2000'],
            'proof' => ['required', 'file', 'image', 'max:10240'],
        ]);

        try {
            $result = $this->reports->submit([
                'topic_id' => (int) $validated['topic_id'],
                'user_id' => (int) $user->id,
                'user_display_name' => (string) ($user->name ?? ''),
                'comment_text' => (string) $validated['comment_text'],
                'seed_link_id' => $validated['seed_link_id'] ?? null,
                'seed_url' => $validated['seed_url'] ?? null,
                'proof' => $request->file('proof'),
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable) {
            return response()->json(['ok' => false, 'message' => 'Không lưu được báo cáo'], 500);
        }

        $userId = (int) $user->id;

        return response()->json([
            'ok' => true,
            'report' => SeedingTopicPresenter::report($result['report']),
            'user_report_count' => $result['user_report_count'],
            'required_report_count' => $result['required'],
            'completed' => $result['completed'],
            'link_usage_today' => $this->topics->linkUsageTodayForUser($userId),
            'message' => $result['completed'] ? 'Đã hoàn thành chủ đề' : 'Đã báo cáo',
        ], 201);
    }
}
