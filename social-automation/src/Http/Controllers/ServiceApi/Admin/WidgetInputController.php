<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers\ServiceApi\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\SocialAutomation\Domain\WidgetInputComposer;
use Omnichannel\Addons\SocialAutomation\PackageRegistry\PackageRegistryService;

/**
 * Manual / AI Auto input composition against a published Widget form schema.
 */
final class WidgetInputController extends Controller
{
    public function __construct(
        private readonly PackageRegistryService $packages,
        private readonly WidgetInputComposer $composer = new WidgetInputComposer,
    ) {}

    public function compose(Request $request): JsonResponse
    {
        $packageKey = trim((string) $request->input('package_key', ''));
        $version = trim((string) $request->input('package_version', ''));
        $mode = strtolower(trim((string) $request->input('mode', 'manual')));

        if ($packageKey === '' || $version === '') {
            return response()->json(['ok' => false, 'errors' => ['validation_failed']], 422);
        }

        $release = $this->packages->findRelease($packageKey, $version);
        if ($release === null) {
            return response()->json(['ok' => false, 'errors' => ['package_not_found']], 404);
        }
        if (strtolower((string) ($release['kind'] ?? '')) !== 'widget') {
            return response()->json(['ok' => false, 'errors' => ['widget_required']], 422);
        }

        $manifest = is_array($release['manifest'] ?? null) ? $release['manifest'] : [];
        $result = $this->composer->compose(
            $manifest,
            $mode,
            is_array($request->input('manual')) ? $request->input('manual') : [],
            is_array($request->input('ai')) ? $request->input('ai') : [],
            is_array($request->input('static')) ? $request->input('static') : [],
        );

        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'errors' => $result['errors'],
                'ai_plan' => $result['ai_plan'],
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'mode' => $result['mode'],
            'inputs' => $result['inputs'],
            'ai_plan' => $result['ai_plan'],
            'field_sources' => $result['field_sources'] ?? [],
            'package_key' => $packageKey,
            'package_version' => $version,
            'content_digest' => $release['content_digest'] ?? null,
        ]);
    }
}
