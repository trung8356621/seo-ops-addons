<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Omnichannel\Addons\Seeding\Enums\SeedingSocialAccountStatus;
use Omnichannel\Addons\Seeding\Enums\SeedingSocialPlatform;
use Omnichannel\Addons\Seeding\Services\SeedingSocialAccountService;
use Omnichannel\Addons\Seeding\Support\SeedingAccess;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Manager-only social account CRUD + credential copy (installation scoped).
 */
final class SeedingManagerSocialAccountsController extends Controller
{
    public function __construct(
        private readonly SeedingSocialAccountService $accounts,
        private readonly SeedingAccess $access,
    ) {}

    public function index(): JsonResponse
    {
        $this->access->assertCanManage();

        $payload = $this->accounts->listForManager();

        return response()->json([
            'ok' => true,
            'accounts' => $payload['accounts'],
            'sites' => $payload['sites'],
            'platforms' => $payload['platforms'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->access->assertCanManage();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $data = $this->validatedWrite($request, creating: true);

        try {
            $row = $this->accounts->create((int) $user->id, $data);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'account' => $row->toManagerApiArray(),
            'message' => 'Đã tạo tài khoản social',
        ], 201);
    }

    public function update(Request $request, int $accountId): JsonResponse
    {
        $this->access->assertCanManage();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $data = $this->validatedWrite($request, creating: false);

        try {
            $row = $this->accounts->update((int) $user->id, $accountId, $data);
        } catch (NotFoundHttpException $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'account' => $row->toManagerApiArray(),
            'message' => 'Đã cập nhật tài khoản social',
        ]);
    }

    public function lock(Request $request, int $accountId): JsonResponse
    {
        $this->access->assertCanManage();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $row = $this->accounts->lock((int) $user->id, $accountId);

        return response()->json([
            'ok' => true,
            'account' => $row->toManagerApiArray(),
            'message' => 'Đã khóa tài khoản',
        ]);
    }

    public function unlock(Request $request, int $accountId): JsonResponse
    {
        $this->access->assertCanManage();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $row = $this->accounts->unlock((int) $user->id, $accountId);

        return response()->json([
            'ok' => true,
            'account' => $row->toManagerApiArray(),
            'message' => 'Đã mở khóa tài khoản',
        ]);
    }

    public function destroy(int $accountId): JsonResponse
    {
        $this->access->assertCanManage();

        $this->accounts->delete($accountId);

        return response()->json([
            'ok' => true,
            'message' => 'Đã xóa tài khoản social',
        ]);
    }

    public function copyUsername(int $accountId): JsonResponse
    {
        $this->access->assertCanManage();

        try {
            $value = $this->accounts->copyUsername($accountId);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'value' => $value,
        ]);
    }

    public function copyPassword(int $accountId): JsonResponse
    {
        $this->access->assertCanManage();

        try {
            $value = $this->accounts->copyPassword($accountId);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'value' => $value,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedWrite(Request $request, bool $creating): array
    {
        $platformRule = Rule::in(SeedingSocialPlatform::values());
        $statusRule = Rule::in(SeedingSocialAccountStatus::values());

        $rules = [
            'site_id' => [$creating ? 'required_without:domain' : 'nullable', 'integer', 'min:1'],
            'domain' => [$creating ? 'required_without:site_id' : 'nullable', 'string', 'max:255'],
            'platform' => [$creating ? 'required' : 'sometimes', 'string', $platformRule],
            'label' => ['nullable', 'string', 'max:255'],
            'username' => [$creating ? 'nullable' : 'nullable', 'string', 'max:500'],
            'password' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'string', $statusRule],
            'meta' => ['nullable', 'array'],
        ];

        return $request->validate($rules);
    }
}
