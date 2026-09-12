<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Omnichannel\Addons\Seeding\Services\SeedingSharedTopicService;
use Omnichannel\Addons\Seeding\Support\SeedingAccess;
use Omnichannel\Addons\Seeding\Support\SeedingTopicPresenter;
use Throwable;

final class SeedingManagerTopicsController extends Controller
{
    public function __construct(
        private readonly SeedingSharedTopicService $topics,
        private readonly SeedingAccess $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->access->assertCanManage();

        $filters = [
            'status' => $request->query('status'),
            'social' => $request->query('social'),
            'created_by' => $request->query('created_by'),
            'search' => $request->query('search'),
        ];

        return response()->json([
            'ok' => true,
            'topics' => $this->topics->managerTopicRows($filters),
            'stats' => $this->topics->managerStats(),
        ]);
    }

    public function pause(int $topicId): JsonResponse
    {
        return $this->mutate($topicId, 'pause');
    }

    public function resume(int $topicId): JsonResponse
    {
        return $this->mutate($topicId, 'resume');
    }

    public function cancel(int $topicId): JsonResponse
    {
        return $this->mutate($topicId, 'cancel');
    }

    private function mutate(int $topicId, string $action): JsonResponse
    {
        $this->access->assertCanManage();

        try {
            $topic = match ($action) {
                'pause' => $this->topics->pauseTopic($topicId),
                'resume' => $this->topics->resumeTopic($topicId),
                'cancel' => $this->topics->cancelTopic($topicId),
                default => throw new InvalidArgumentException('Action không hợp lệ'),
            };
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable) {
            return response()->json(['ok' => false, 'message' => 'Không cập nhật được chủ đề'], 500);
        }

        return response()->json([
            'ok' => true,
            'topic' => SeedingTopicPresenter::managerRow($topic),
        ]);
    }
}
