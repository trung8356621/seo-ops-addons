<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Omnichannel\Addons\Seeding\Services\SeedingCommentGenerateHistoryService;
use Omnichannel\Addons\Seeding\Services\SeedingCommentPromptService;
use Omnichannel\Addons\Seeding\Support\SeedingAccess;
use Omnichannel\Addons\Seeding\Support\SeedingCommentPromptDefaults;

/**
 * Manager: editable Gen Comment prompt + debug history (max 20).
 */
final class SeedingCommentPromptController
{
    public function show(
        SeedingAccess $access,
        SeedingCommentPromptService $prompts,
        SeedingCommentGenerateHistoryService $history,
    ): JsonResponse {
        $access->assertCanManage();

        return response()->json([
            'prompt_body' => $prompts->getPromptBody(),
            'supported_variables' => $prompts->supportedVariables(),
            'history' => $this->mapHistoryList($history->latest()),
        ]);
    }

    public function update(
        Request $request,
        SeedingAccess $access,
        SeedingCommentPromptService $prompts,
    ): JsonResponse {
        $access->assertCanManage();

        try {
            $validated = $request->validate([
                'prompt_body' => ['required', 'string', 'max:100000'],
            ]);
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first();

            return response()->json([
                'message' => is_string($first) && $first !== '' ? $first : $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        }

        $body = $prompts->savePromptBody((string) $validated['prompt_body']);

        return response()->json([
            'prompt_body' => $body,
            'supported_variables' => $prompts->supportedVariables(),
            'message' => 'Đã lưu Prompt Gen Comment.',
        ]);
    }

    public function history(
        SeedingAccess $access,
        SeedingCommentGenerateHistoryService $history,
    ): JsonResponse {
        $access->assertCanManage();

        return response()->json([
            'history' => $this->mapHistoryList($history->latest()),
            'max' => SeedingCommentGenerateHistoryService::MAX_LOGS,
        ]);
    }

    public function historyShow(
        int $slot,
        SeedingAccess $access,
        SeedingCommentGenerateHistoryService $history,
    ): JsonResponse {
        $access->assertCanManage();

        $row = $history->findBySlot($slot);
        if ($row === null) {
            return response()->json(['message' => 'Không tìm thấy log Gen Comment.'], 404);
        }

        return response()->json([
            'log' => $this->mapHistoryDetail($row),
            'supported_variables' => [SeedingCommentPromptDefaults::MCP_CONTEXT_VAR],
        ]);
    }

    /**
     * @param  list<\Omnichannel\Addons\Seeding\Models\SeedingCommentGenerateLog>  $rows
     * @return list<array<string, mixed>>
     */
    private function mapHistoryList(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'slot' => (int) $row->slot,
                'sequence' => (int) $row->sequence,
                'topic_id' => $row->topic_id !== null ? (int) $row->topic_id : null,
                'social' => $row->social,
                'quantity' => (int) $row->quantity,
                'provider' => $row->provider,
                'model' => $row->model,
                'status' => $row->status,
                'error_message' => $row->error_message,
                'generated_at' => optional($row->generated_at)?->toIso8601String(),
                'generated_at_label' => optional($row->generated_at)?->format('d/m H:i'),
            ];
        }

        return $out;
    }

    /**
     * @param  \Omnichannel\Addons\Seeding\Models\SeedingCommentGenerateLog  $row
     * @return array<string, mixed>
     */
    private function mapHistoryDetail(object $row): array
    {
        return [
            'slot' => (int) $row->slot,
            'sequence' => (int) $row->sequence,
            'topic_id' => $row->topic_id !== null ? (int) $row->topic_id : null,
            'social' => $row->social,
            'quantity' => (int) $row->quantity,
            'mcp_context' => (string) $row->mcp_context,
            'final_prompt' => (string) $row->final_prompt,
            'ai_output' => $row->ai_output,
            'provider' => $row->provider,
            'model' => $row->model,
            'status' => $row->status,
            'error_message' => $row->error_message,
            'generated_at' => optional($row->generated_at)?->toIso8601String(),
            'generated_at_label' => optional($row->generated_at)?->format('d/m/Y H:i:s'),
        ];
    }
}
