<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\Seeding\Services\SeedingCommentGenerateService;
use Omnichannel\Addons\Seeding\Support\SeedingAccess;
use Throwable;

/**
 * Stateless AI sample-comment generation.
 * No Seeding topic/comment persistence.
 */
final class SeedingCommentGenerateController
{
    public function __invoke(
        Request $request,
        SeedingAccess $access,
        SeedingCommentGenerateService $generator,
    ): JsonResponse {
        $access->assertCanAccess();

        $rawSource = strtolower(trim((string) $request->input('source_type', '')));
        $rawSocial = trim((string) ($request->input('social') ?? $request->input('platform') ?? ''));

        $knownPlatforms = ['threads', 'facebook', 'instagram', 'tiktok', 'twitter', 'x', 'youtube', 'linkedin'];
        if ($rawSocial === '' && in_array($rawSource, $knownPlatforms, true)) {
            $rawSocial = $rawSource;
        }

        $validated = $request->validate([
            'source_type' => ['nullable', 'string', 'max:64'],
            'content' => ['nullable', 'string', 'max:20000'],
            'url' => ['nullable', 'string', 'max:2000'],
            'social' => ['nullable', 'string', 'max:64'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:12'],
            'full_text' => ['nullable', 'string', 'max:20000'],
            'social_url' => ['nullable', 'string', 'max:2000'],
            'platform' => ['nullable', 'string', 'max:64'],
            'count' => ['nullable', 'integer', 'min:1', 'max:12'],
            'title' => ['nullable', 'string', 'max:1000'],
            'description' => ['nullable', 'string', 'max:5000'],
            'preview_title' => ['nullable', 'string', 'max:1000'],
            'preview_description' => ['nullable', 'string', 'max:5000'],
            'domain' => ['nullable', 'string', 'max:255'],
        ]);

        $content = trim((string) ($validated['content'] ?? $validated['full_text'] ?? ''));
        $url = trim((string) ($validated['url'] ?? $validated['social_url'] ?? ''));

        if ($content === '' && $url === '') {
            return response()->json([
                'message' => 'Thiếu nội dung gốc hoặc link để gen nội dung seeding.',
                'comments' => [],
            ], 422);
        }

        // Canonical source_type is strictly 'text' or 'url'
        $validated['source_type'] = ($rawSource === 'url' || ($url !== '' && $content === '')) ? 'url' : 'text';
        $validated['social'] = $rawSocial !== '' ? $rawSocial : ($validated['social'] ?? 'threads');
        $validated['quantity'] = $validated['quantity'] ?? $validated['count'] ?? 3;

        try {
            $comments = $generator->generateFromPayload($validated);
        } catch (Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'comments' => [],
            ], 422);
        }

        return response()->json([
            'comments' => $comments,
            'persisted' => false,
        ]);
    }
}
