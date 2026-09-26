<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Http\Controllers\ServiceApi;

use App\Api\Services\ServiceApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ServiceApi\ServiceApiDraftIntakeService;
use Throwable;

/**
 * Permanent Service API — Shared Planning Draft intake (Agent-facing write).
 * Scope: content-projects:draft:write. No publishing lifecycle.
 */
final class ContentProjectDraftIntakeController
{
    public function __construct(
        private readonly ServiceApiDraftIntakeService $intake,
    ) {}

    public function store(Request $request, string $service): JsonResponse
    {
        unset($service);

        $payload = $request->all();
        if (! is_array($payload)) {
            return ServiceApiError::validationFailed('Request body must be a JSON object.');
        }

        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
        if ($idempotencyKey === '') {
            $idempotencyKey = null;
        }

        try {
            $result = $this->intake->intake($payload, $idempotencyKey);
        } catch (InvalidArgumentException $e) {
            return ServiceApiError::validationFailed($e->getMessage());
        } catch (Throwable $e) {
            return ServiceApiError::json(
                'service_api_failed',
                $e->getMessage() !== '' ? $e->getMessage() : 'Draft intake failed.',
                500,
            );
        }

        $status = $result->added > 0 ? 201 : 200;

        return response()->json([
            'data' => $result->toArray(),
        ], $status)->header('Cache-Control', 'no-store');
    }
}
