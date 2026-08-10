<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Http\Controllers;

use Omnichannel\Addons\AiPrompt\Http\Requests\PromptHookExecuteRequest;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookException;
use Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookExecutionService;
use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookHttpStatus;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * POST /api/seo/prompt-hooks/{hookKey}/execute
 *
 * Không lưu article / SEO meta / WordPress sync.
 */
final class PromptHookExecuteController extends Controller
{
    public function __construct(
        private readonly PromptHookExecutionService $execution,
    ) {}

    public function __invoke(PromptHookExecuteRequest $request, string $hookKey): JsonResponse
    {
        abort_unless(SeoAccessControl::canMutateInSeoPanel(), 403);

        return $this->executeHook(
            hookKey: $hookKey,
            articleId: $request->articleId(),
            runtimeInput: $request->runtimeInput(),
        );
    }

    /**
     * @param  array<string, mixed>  $runtimeInput
     */
    public function executeHook(string $hookKey, int $articleId, array $runtimeInput = []): JsonResponse
    {
        $hookKey = trim(rawurldecode($hookKey));
        if ($hookKey === '') {
            return response()->json([
                'success' => false,
                'error' => 'HOOK_NOT_FOUND',
                'message' => 'Hook key is required.',
            ], 404);
        }

        try {
            $result = $this->execution->execute(
                hookKey: $hookKey,
                articleId: $articleId,
                runtimeInput: $runtimeInput,
            );
        } catch (PromptHookException $exception) {
            return response()->json([
                'success' => false,
                'error' => $exception->errorCodeValue(),
                'message' => $exception->getMessage(),
            ], PromptHookHttpStatus::for($exception->errorCode));
        } catch (Throwable) {
            return response()->json([
                'success' => false,
                'error' => 'HOOK_EXECUTION_FAILED',
                'message' => 'Prompt hook execution failed.',
            ], 502);
        }

        return response()->json([
            'success' => true,
            'data' => $result->toApiData(),
        ]);
    }
}
