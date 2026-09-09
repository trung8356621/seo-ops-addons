<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Omnichannel\Addons\Seeding\Services\SeedingSharedTopicService;
use Omnichannel\Addons\Seeding\Support\SeedingAccess;
use Omnichannel\Addons\Seeding\Support\SeedingTopicPresenter;
use Throwable;

final class SeedingShareTopicController extends Controller
{
    public function __construct(
        private readonly SeedingSharedTopicService $topics,
        private readonly SeedingAccess $access,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->access->assertCanMutate();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'full_text' => ['required', 'string', 'max:50000'],
            'source_html' => ['nullable', 'string', 'max:200000'],
            'social_url' => ['nullable', 'string', 'max:2000'],
            'links' => ['nullable', 'array', 'max:50'],
            'links.*.url' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $topic = $this->topics->share([
                'title' => $validated['title'] ?? null,
                'full_text' => (string) $validated['full_text'],
                'source_html' => $validated['source_html'] ?? null,
                'social_url' => $validated['social_url'] ?? null,
                'links' => is_array($validated['links'] ?? null) ? $validated['links'] : [],
                'created_by' => (int) $user->id,
                'created_by_display_name' => (string) ($user->name ?? ''),
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable) {
            return response()->json(['ok' => false, 'message' => 'Không chia sẻ được chủ đề'], 500);
        }

        return response()->json([
            'ok' => true,
            'topic' => SeedingTopicPresenter::topic($topic),
            'message' => 'Đã chia sẻ chủ đề',
        ], 201);
    }
}
