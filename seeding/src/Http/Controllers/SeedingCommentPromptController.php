<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultSeedingCommentPromptInstaller;
use Omnichannel\Addons\Seeding\Services\SeedingCommentGenerateHistoryService;
use Omnichannel\Addons\Seeding\Services\SeedingSharedCommentPromptResolver;
use Omnichannel\Addons\Seeding\Support\SeedingAccess;
use Omnichannel\Addons\Seeding\Support\SeedingCommentPromptDefaults;
use Throwable;

/**
 * Manager: read-only Gen Comment prompt mirror + debug history (max 20).
 * Prompt edit authority = shared Prompt management UI (PromptResource).
 */
final class SeedingCommentPromptController
{
    public function show(
        SeedingAccess $access,
        SeedingSharedCommentPromptResolver $sharedPrompt,
        SeedingCommentGenerateHistoryService $history,
    ): JsonResponse {
        $access->assertCanManage();

        try {
            $active = $sharedPrompt->resolveActive();
        } catch (Throwable $e) {
            return response()->json([
                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Shared Prompt chưa cấu hình.',
                'prompt_body' => '',
                'editable' => false,
                'authority' => 'shared_prompt',
                'hook_key' => DefaultSeedingCommentPromptInstaller::HOOK_KEY,
                'supported_variables' => $sharedPrompt->supportedVariables(),
                'history' => $this->mapHistoryList($history->latest()),
            ], 503);
        }

        $editUrl = null;
        try {
            $editUrl = PromptResource::getUrl('edit', ['record' => $active['prompt_id']]);
        } catch (Throwable) {
            $editUrl = null;
        }

        return response()->json([
            'prompt_body' => $active['body'],
            'prompt_id' => $active['prompt_id'],
            'prompt_version_id' => $active['prompt_version_id'],
            'hook_key' => $active['hook_key'],
            'hook_version' => $active['hook_version'],
            'editable' => false,
            'authority' => 'shared_prompt',
            'edit_url' => $editUrl,
            'supported_variables' => $sharedPrompt->supportedVariables(),
            'history' => $this->mapHistoryList($history->latest()),
            'message' => 'Prompt Gen Comment được quản lý trong Prompt Management. Seeding không còn quyền sửa tại đây.',
        ]);
    }

    public function update(
        Request $request,
        SeedingAccess $access,
        SeedingSharedCommentPromptResolver $sharedPrompt,
    ): JsonResponse {
        $access->assertCanManage();
        unset($request);

        $editUrl = null;
        $promptId = null;
        try {
            $active = $sharedPrompt->resolveActive();
            $promptId = $active['prompt_id'];
            $editUrl = PromptResource::getUrl('edit', ['record' => $promptId]);
        } catch (Throwable) {
            // keep nulls
        }

        return response()->json([
            'message' => 'Prompt Gen Comment đã chuyển sang Prompt Management. Vui lòng chỉnh sửa tại Prompt UI.',
            'authority' => 'shared_prompt',
            'editable' => false,
            'hook_key' => DefaultSeedingCommentPromptInstaller::HOOK_KEY,
            'prompt_id' => $promptId,
            'edit_url' => $editUrl,
        ], 409);
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
