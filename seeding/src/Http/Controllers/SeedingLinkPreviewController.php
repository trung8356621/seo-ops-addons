<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Http\Controllers;

use Omnichannel\Addons\Seeding\Services\SeedingLinkPreviewService;
use Omnichannel\Addons\Seeding\Support\SeedingAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SeedingLinkPreviewController
{
    public function __construct(
        private readonly SeedingAccess $access,
        private readonly SeedingLinkPreviewService $previews,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->access->assertCanAccess($request->user());

        $url = trim((string) $request->input('url', ''));
        if ($url === '') {
            return response()->json([
                'ok' => false,
                'message' => 'url is required',
                'preview_url' => '',
                'preview_title' => null,
                'preview_description' => null,
                'preview_image_url' => null,
                'preview_domain' => null,
                'preview_fetched_at' => now()->toIso8601String(),
                'preview_status' => 'invalid',
                'error' => 'url is required',
            ], 422);
        }

        $result = $this->previews->fetch($url);

        return response()->json($result);
    }
}
