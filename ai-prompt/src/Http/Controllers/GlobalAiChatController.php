<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Http\Controllers;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Services\GlobalAiChatService;
use App\Http\Controllers\Controller;
use App\Support\RuntimeLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\File;
use Throwable;

/**
 * @deprecated HTTP routes removed — Agent Workspace is sole chat/execution surface.
 * Class retained temporarily for reference / possible DI leftovers; do not re-register.
 */
final class GlobalAiChatController extends Controller
{
    public function models(GlobalAiChatService $chat): JsonResponse
    {
        return response()->json([
            'models' => $chat->availableModels(),
        ]);
    }

    public function store(Request $request, GlobalAiChatService $chat): JsonResponse
    {
        $validated = $request->validate([
            'model' => ['required', 'integer', 'min:1'],
            'message' => ['nullable', 'string', 'max:20000', 'required_without:image'],
            'image' => [
                'nullable',
                File::image()
                    ->max('8mb')
                    ->types(['jpg', 'jpeg', 'png', 'webp', 'gif']),
            ],
            'history' => ['nullable', 'array', 'max:12'],
            'history.*.role' => ['required', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:12000'],
        ]);

        try {
            $result = $chat->chat(
                (int) $validated['model'],
                (string) ($validated['message'] ?? ''),
                is_array($validated['history'] ?? null) ? $validated['history'] : [],
                $request->file('image'),
            );

            return response()->json($result);
        } catch (PromptRunException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $exception) {
            RuntimeLogger::report($exception);

            return response()->json([
                'message' => 'Không thể kết nối trợ lý AI. Vui lòng thử lại.',
            ], 500);
        }
    }
}
