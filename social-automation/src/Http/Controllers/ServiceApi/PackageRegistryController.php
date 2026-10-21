<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers\ServiceApi;

use App\Api\Services\ServiceApiError;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackageRegistryService;

/**
 * Admin → SEO-OPS package registry API.
 * Auth: service_api_credentials (never service_key).
 */
final class PackageRegistryController extends Controller
{
    public function __construct(
        private readonly PackageRegistryService $registry,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'packages' => $this->registry->listPackages(),
        ]);
    }

    public function show(string $service, string $packageKey): JsonResponse
    {
        $version = request()->query('version');
        if (! is_string($version) || trim($version) === '') {
            return response()->json([
                'ok' => true,
                'packages' => array_values(array_filter(
                    $this->registry->listPackages(),
                    static fn (array $row): bool => ($row['package_key'] ?? '') === $packageKey
                )),
            ]);
        }

        $release = $this->registry->findRelease($packageKey, trim($version));
        if ($release === null) {
            return ServiceApiError::notFound('Package release not found.');
        }

        return response()->json([
            'ok' => true,
            'release' => $release,
        ]);
    }

    public function publish(Request $request): JsonResponse
    {
        $payload = $request->all();
        if (! is_array($payload)) {
            $payload = [];
        }

        $result = $this->registry->publish($payload);

        return response()->json($result->toArray(), $result->httpStatus());
    }
}
