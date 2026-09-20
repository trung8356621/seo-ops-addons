<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Omnichannel\Addons\Seeding\Services\SeedingCommentGenerateService;
use Omnichannel\Addons\Seeding\Services\SeedingSocialContextResolver;
use Omnichannel\Addons\Seeding\Support\SeedingAccess;
use Throwable;

/**
 * AI sample-comment generation boundary.
 * Normalizes source → SystemAiClient (seeding.comment.generate).
 * Writes lightweight Gen Comment debug history (max 20); no topic/comment rows.
 */
final class SeedingCommentGenerateController
{
    public function __invoke(
        Request $request,
        SeedingAccess $access,
        SeedingCommentGenerateService $generator,
        SeedingSocialContextResolver $resolver,
    ): JsonResponse {
        $access->assertCanAccess();

        try {
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
                'topic_id' => ['nullable', 'integer', 'min:1'],
                'title' => ['nullable', 'string', 'max:1000'],
                'description' => ['nullable', 'string', 'max:5000'],
                'preview_title' => ['nullable', 'string', 'max:1000'],
                'preview_description' => ['nullable', 'string', 'max:5000'],
                'domain' => ['nullable', 'string', 'max:255'],
            ]);
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first();

            return response()->json([
                'message' => is_string($first) && $first !== '' ? $first : $e->getMessage(),
                'errors' => $e->errors(),
                'comments' => [],
            ], 422);
        }

        $content = trim((string) ($validated['content'] ?? $validated['full_text'] ?? ''));
        $url = trim((string) ($validated['url'] ?? $validated['social_url'] ?? ''));
        $rawSocial = trim((string) ($validated['social'] ?? $validated['platform'] ?? ''));
        $rawSource = strtolower(trim((string) ($validated['source_type'] ?? '')));

        $knownPlatforms = ['threads', 'facebook', 'instagram', 'tiktok', 'twitter', 'x', 'youtube', 'linkedin'];
        if ($rawSocial === '' && in_array($rawSource, $knownPlatforms, true)) {
            $rawSocial = $rawSource;
        }

        if ($content === '' && $url === '') {
            return response()->json([
                'message' => 'Thiếu nội dung gốc hoặc link để gen nội dung seeding.',
                'comments' => [],
            ], 422);
        }

        // Single boundary normalization — never pass topic/platform enums as source_type.
        $payload = [
            'content' => $content,
            'full_text' => $content,
            'url' => $url,
            'social_url' => $url,
            'source_type' => $resolver->determineCanonicalSourceType([
                'source_type' => $rawSource,
                'content' => $content,
                'url' => $url,
            ], $content, $url),
            'social' => $rawSocial !== '' ? $rawSocial : 'threads',
            'quantity' => (int) ($validated['quantity'] ?? $validated['count'] ?? 3),
            'topic_id' => $validated['topic_id'] ?? null,
            'title' => $validated['title'] ?? null,
            'description' => $validated['description'] ?? null,
            'preview_title' => $validated['preview_title'] ?? null,
            'preview_description' => $validated['preview_description'] ?? null,
            'domain' => $validated['domain'] ?? null,
        ];

        try {
            $comments = $generator->generateFromPayload($payload);
        } catch (Throwable $e) {
            return response()->json([
                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Gen comment thất bại.',
                'comments' => [],
            ], 422);
        }

        return response()->json([
            'comments' => $comments,
            'persisted' => false,
        ]);
    }
}
