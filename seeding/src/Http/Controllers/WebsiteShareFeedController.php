<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Omnichannel\Addons\Seeding\Enums\SeedingSocialPlatform;
use Omnichannel\Addons\Seeding\Services\WebsiteShareJobService;
use Omnichannel\Addons\Seeding\Support\SeedingAccess;
use Omnichannel\Addons\Seeding\Support\WebsiteSharePresenter;
use Throwable;

final class WebsiteShareFeedController extends Controller
{
    public function __construct(
        private readonly WebsiteShareJobService $jobs,
        private readonly SeedingAccess $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->access->assertCanAccess();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json([
            'ok' => true,
            'jobs' => $this->jobs->feedForUser((int) $user->id, $request->query('filter')),
        ]);
    }

    public function updateContent(Request $request, int $jobId): JsonResponse
    {
        $this->access->assertCanMutate();

        $validated = $request->validate([
            'share_content' => ['nullable', 'string', 'max:20000'],
        ]);

        try {
            $job = $this->jobs->updateShareContent($jobId, (string) ($validated['share_content'] ?? ''));
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable) {
            return response()->json(['ok' => false, 'message' => 'Không lưu được nội dung'], 500);
        }

        return response()->json([
            'ok' => true,
            'job' => WebsiteSharePresenter::feedCard($job->loadMissing('targets')),
        ]);
    }

    public function report(Request $request, int $jobId): JsonResponse
    {
        $this->access->assertCanMutate();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'social' => ['required', 'string', Rule::in(SeedingSocialPlatform::values())],
            'share_text' => ['nullable', 'string', 'max:20000'],
        ]);

        try {
            $result = $this->jobs->submitShareReport([
                'job_id' => $jobId,
                'social' => (string) $validated['social'],
                'user_id' => (int) $user->id,
                'user_display_name' => (string) ($user->name ?? ''),
                'share_text' => $validated['share_text'] ?? null,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable) {
            return response()->json(['ok' => false, 'message' => 'Không lưu được báo cáo share'], 500);
        }

        return response()->json([
            'ok' => true,
            'job' => WebsiteSharePresenter::feedCard($result['job']),
            'target' => WebsiteSharePresenter::target($result['target']),
            'message' => 'Đã báo cáo chia sẻ',
        ], 201);
    }
}
