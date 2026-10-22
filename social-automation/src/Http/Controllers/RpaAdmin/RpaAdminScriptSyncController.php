<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers\RpaAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Auth\RpaAdminSessionService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackageRegistryService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptCatalogService;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\ScriptSyncPlanner;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationApiStatus;

/**
 * Bidirectional Script sync for RPA Admin (user session auth).
 */
final class RpaAdminScriptSyncController extends Controller
{
    public function __construct(
        private readonly ScriptCatalogService $catalog,
        private readonly ScriptSyncPlanner $planner,
        private readonly PackageRegistryService $registry,
    ) {}

    public function catalog(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'scripts' => $this->catalog->listScripts(),
        ]);
    }

    public function plan(Request $request): JsonResponse
    {
        $local = $request->input('local_scripts', []);
        if (! is_array($local)) {
            return response()->json(['ok' => false, 'errors' => ['local_scripts_required']], 422);
        }

        /** @var list<array<string, mixed>> $normalized */
        $normalized = [];
        foreach ($local as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalized[] = [
                'script_id' => (string) ($row['script_id'] ?? ''),
                'package_key' => (string) ($row['package_key'] ?? ''),
                'version' => (string) ($row['version'] ?? ''),
                'content_digest' => (string) ($row['content_digest'] ?? ''),
                'execution_digest' => isset($row['execution_digest']) ? (string) $row['execution_digest'] : null,
                'lineage' => is_array($row['lineage'] ?? null) ? $row['lineage'] : [],
                'assets_complete' => (bool) ($row['assets_complete'] ?? true),
            ];
        }

        $plan = $this->planner->plan($normalized, $this->catalog->listScripts());

        return response()->json(['ok' => true, 'plan' => $plan]);
    }

    public function publish(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->attributes->get(RpaAdminSessionService::REQUEST_USER_KEY);
        $payload = $request->all();
        if (! is_array($payload)) {
            $payload = [];
        }
        unset($payload['user_id'], $payload['id']);

        $result = $this->registry->publish($payload, [
            'user_id' => $user instanceof User ? (int) $user->id : null,
            'sync_source' => 'rpa_admin',
        ]);

        return response()->json($result->toArray(), $result->httpStatus());
    }

    public function pull(Request $request): JsonResponse
    {
        $packageKey = trim((string) $request->input('package_key', ''));
        $version = trim((string) $request->input('version', ''));
        if ($packageKey === '' || $version === '') {
            return response()->json(['ok' => false, 'errors' => ['package_key_version_required']], 422);
        }

        $release = $this->registry->findRelease($packageKey, $version);
        if ($release === null || strtolower((string) ($release['kind'] ?? '')) !== 'script') {
            return response()->json(['ok' => false, 'errors' => ['script_not_found']], 404);
        }

        $manifest = is_array($release['manifest'] ?? null) ? $release['manifest'] : [];
        $assets = is_array($manifest['assets'] ?? null) ? $manifest['assets'] : [];
        $assetsComplete = true;
        foreach ($assets as $asset) {
            if (! is_array($asset) || trim((string) ($asset['path'] ?? '')) === ''
                || ! preg_match('/^[a-f0-9]{64}$/', strtolower((string) ($asset['sha256'] ?? '')))) {
                $assetsComplete = false;
                break;
            }
        }

        return response()->json([
            'ok' => true,
            'release' => $release,
            'assets_complete' => $assetsComplete,
            'ready_to_execute' => $assetsComplete,
        ], SocialAutomationApiStatus::fromResult(true));
    }
}
