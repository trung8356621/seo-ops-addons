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
        $this->access->assertCanManage();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $platformValues = SeedingSocialPlatform::values();

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'full_text' => ['required', 'string', 'max:50000'],
            'source_html' => ['nullable', 'string', 'max:200000'],
            'social_url' => ['nullable', 'string', 'max:2000'],
            'social_platform' => ['nullable', 'string', Rule::in($platformValues)],
            'target_comments' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'social_targets' => ['nullable', 'array', 'min:1', 'max:20'],
            'social_targets.*.social_platform' => ['required_with:social_targets', 'string', Rule::in($platformValues)],
            'social_targets.*.target_comments' => ['required_with:social_targets', 'integer', 'min:1', 'max:10000'],
            'source_type' => ['nullable', 'string', 'max:32'],
            'links' => ['nullable', 'array', 'max:50'],
            'links.*.url' => ['nullable', 'string', 'max:2000'],
        ]);

        if (
            empty($validated['social_targets'])
            && empty($validated['social_platform'])
            && empty($validated['social_url'])
        ) {
            return response()->json(['ok' => false, 'message' => 'Social là bắt buộc'], 422);
        }

        try {
            $created = $this->topics->share([
                'title' => $validated['title'] ?? null,
                'full_text' => (string) $validated['full_text'],
                'source_html' => $validated['source_html'] ?? null,
                'social_url' => $validated['social_url'] ?? null,
                'social_platform' => $validated['social_platform'] ?? null,
                'target_comments' => $validated['target_comments'] ?? null,
                'social_targets' => $validated['social_targets'] ?? null,
                'source_type' => $validated['source_type'] ?? 'manual',
                'links' => is_array($validated['links'] ?? null) ? $validated['links'] : [],
                'created_by' => (int) $user->id,
                'created_by_display_name' => (string) ($user->name ?? ''),
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable) {
            return response()->json(['ok' => false, 'message' => 'Không chia sẻ được chủ đề'], 500);
        }

        $topics = array_map(
            static fn ($topic) => SeedingTopicPresenter::topic($topic),
            $created
        );

        return response()->json([
            'ok' => true,
            'topics' => $topics,
            'topic' => $topics[0] ?? null,
            'message' => count($topics) > 1
                ? 'Đã tạo '.count($topics).' topic execution'
                : 'Đã chia sẻ chủ đề',
        ], 201);
    }
}
