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

        $validated = $request->validate([
            'full_text' => ['required', 'string', 'max:20000'],
            'social_url' => ['nullable', 'string', 'max:2000'],
            'platform' => ['nullable', 'string', 'max:64'],
            'count' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        try {
            $comments = $generator->generate(
                (string) $validated['full_text'],
                isset($validated['social_url']) ? (string) $validated['social_url'] : null,
                isset($validated['platform']) ? (string) $validated['platform'] : null,
                (int) ($validated['count'] ?? 5),
            );
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
