<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Http\Controllers\ServiceApi;

use App\Api\Access\TemporaryServiceAccessManager;
use App\Api\Middleware\AuthenticateServiceApi;
use App\Api\Services\ServiceApiContext;
use App\Api\Services\ServiceApiError;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Omnichannel\Addons\Seo\Services\Access\SeoAccessSiteKnowledgeComposer;

/**
 * Permanent Service API — SEO Access index + mint temporary site-bound access.
 */
final class SeoAccessController
{
    public function __construct(
        private readonly TemporaryServiceAccessManager $temporaryAccess,
        private readonly SeoAccessSiteKnowledgeComposer $siteKnowledge,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $sites = [];
        if (Schema::hasTable('sites')) {
            $query = Site::query()->orderBy('id');
            if (Schema::hasColumn('sites', 'status')) {
                $query->where('status', 'active');
            }
            foreach ($query->limit(500)->get() as $site) {
                if (! $site instanceof Site) {
                    continue;
                }
                $sites[] = $this->siteKnowledge->listRow($site);
            }
        }

        return response()->json([
            'data' => [
                'service' => 'seo',
                'sites' => $sites,
                'access' => [
                    'method' => 'POST',
                    'href' => '/api/v1/services/seo/access',
                ],
            ],
        ]);
    }

    public function mint(Request $request, string $service): JsonResponse
    {
        $payload = $request->all();
        if (! is_array($payload)) {
            return ServiceApiError::validationFailed('Request body must be a JSON object.');
        }

        $unknown = array_diff(array_keys($payload), ['site_id', 'scope']);
        if ($unknown !== []) {
            return ServiceApiError::validationFailed(
                'Unknown request fields: '.implode(', ', array_values($unknown))
            );
        }

        $scope = isset($payload['scope']) ? strtolower(trim((string) $payload['scope'])) : 'site';
        if (! in_array($scope, ['site', 'global'], true)) {
            return ServiceApiError::validationFailed("Invalid scope: {$scope}. Allowed scopes: 'site', 'global'.");
        }

        $siteId = null;
        if ($scope === 'site') {
            if (! array_key_exists('site_id', $payload) || ! is_numeric($payload['site_id'])) {
                return ServiceApiError::validationFailed('site_id is required and must be a positive integer.');
            }

            $siteId = (int) $payload['site_id'];
            if ($siteId <= 0) {
                return ServiceApiError::validationFailed('site_id is required and must be a positive integer.');
            }

            if (! $this->siteExists($siteId)) {
                return ServiceApiError::validationFailed('Unknown or invalid site_id.');
            }
        } elseif (array_key_exists('site_id', $payload) && $payload['site_id'] !== null) {
            return ServiceApiError::validationFailed('site_id must not be provided when scope is global.');
        }

        $context = $request->attributes->get(AuthenticateServiceApi::REQUEST_CONTEXT_KEY);
        if (! $context instanceof ServiceApiContext) {
            $context = app()->bound(ServiceApiContext::class)
                ? app(ServiceApiContext::class)
                : null;
        }
        if (! $context instanceof ServiceApiContext) {
            return ServiceApiError::unauthorized();
        }

        try {
            $issued = $scope === 'global'
                ? $this->temporaryAccess->issueGlobal(
                    $context->service,
                    $context->credential,
                )
                : $this->temporaryAccess->issue(
                    $context->service,
                    $context->credential,
                    (int) $siteId,
                );
        } catch (InvalidArgumentException $e) {
            return ServiceApiError::validationFailed($e->getMessage());
        }

        $accessUrl = url('/api/v1/access/'.$issued->rawToken);

        $responseData = [
            'access_url' => $accessUrl,
            'expires_at' => $issued->expiresAt,
            'scope' => $issued->scope,
            'site_ref' => $issued->siteRef(),
        ];

        return response()->json([
            'data' => $responseData,
        ])->header('Cache-Control', 'no-store');
    }

    private function siteExists(int $siteId): bool
    {
        if ($siteId <= 0 || ! Schema::hasTable('sites')) {
            return false;
        }

        return Site::query()->whereKey($siteId)->exists();
    }
}
