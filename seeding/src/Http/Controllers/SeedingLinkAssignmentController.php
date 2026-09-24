<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Omnichannel\Addons\Seeding\Services\SeedingLinkAssignmentService;
use Omnichannel\Addons\Seeding\Support\SeedingAccess;
use Throwable;

/**
 * Creator/Manager CRUD for DB-backed seeding link assignments.
 * Exact Seeder must never mutate assignment definitions.
 */
final class SeedingLinkAssignmentController extends Controller
{
    public function __construct(
        private readonly SeedingLinkAssignmentService $assignments,
        private readonly SeedingAccess $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->access->assertCanManageLinkAssignments();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $activeOnly = filter_var($request->query('active_only', false), FILTER_VALIDATE_BOOLEAN);

        return response()->json([
            'ok' => true,
            'assignments' => $this->assignments->listForOwner((int) $user->id, $activeOnly),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->access->assertCanManageLinkAssignments();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:2000'],
            'target_per_day' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        try {
            $row = $this->assignments->create((int) $user->id, $validated);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable) {
            return response()->json(['ok' => false, 'message' => 'Không tạo được link assignment'], 500);
        }

        return response()->json([
            'ok' => true,
            'assignment' => $row->toApiArray(),
        ], 201);
    }

    public function update(Request $request, int $assignmentId): JsonResponse
    {
        $this->access->assertCanManageLinkAssignments();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'string', 'max:2000'],
            'target_per_day' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        try {
            $row = $this->assignments->update((int) $user->id, $assignmentId, $validated);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable) {
            return response()->json(['ok' => false, 'message' => 'Không cập nhật được link assignment'], 500);
        }

        return response()->json([
            'ok' => true,
            'assignment' => $row->toApiArray(),
        ]);
    }

    public function destroy(Request $request, int $assignmentId): JsonResponse
    {
        $this->access->assertCanManageLinkAssignments();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        try {
            $this->assignments->delete((int) $user->id, $assignmentId);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable) {
            return response()->json(['ok' => false, 'message' => 'Không xóa được link assignment'], 500);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Optional one-time import of legacy local seed_links → DB.
     */
    public function importLocal(Request $request): JsonResponse
    {
        $this->access->assertCanManageLinkAssignments();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'links' => ['required', 'array', 'max:100'],
            'links.*.url' => ['required', 'string', 'max:2000'],
            'links.*.title' => ['nullable', 'string', 'max:255'],
            'links.*.label' => ['nullable', 'string', 'max:255'],
            'links.*.target_per_day' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'links.*.daily_limit' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'links.*.is_active' => ['nullable', 'boolean'],
        ]);

        try {
            $created = $this->assignments->importLocalLinks(
                (int) $user->id,
                is_array($validated['links'] ?? null) ? $validated['links'] : [],
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable) {
            return response()->json(['ok' => false, 'message' => 'Import thất bại'], 500);
        }

        return response()->json([
            'ok' => true,
            'created' => $created,
            'assignments' => $this->assignments->listForOwner((int) $user->id),
            'message' => count($created) > 0
                ? 'Đã import '.count($created).' link vào DB'
                : 'Không có link mới để import',
        ]);
    }
}
