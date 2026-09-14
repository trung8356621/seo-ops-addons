<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Services;

use Omnichannel\Addons\AiPrompt\Services\CanonicalAiTextExecutionService;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\Social\Ai\Exceptions\SocialAiException;
use Omnichannel\Addons\Social\Ai\Services\SocialAiExecutionService;
use Omnichannel\Addons\Social\Ai\Tasks\SocialCommentGenerateTask;
use RuntimeException;
use Throwable;

/**
 * Gen Comment via Client Social AI task + shared router.
 * API response still uses { comments: string[] } for contract stability;
 * Flexible Seeding JS adapter maps that to seed_outputs.
 *
 * Flow: MCP context → Manager prompt → {{mcp_context}} → AI → history snapshot (max 20).
 * Business writing style lives only in the Manager prompt.
 */
final class SeedingCommentGenerateService
{
    /** @deprecated Use SocialCommentGenerateTask::TASK_KEY */
    public const HOOK_KEY = 'seeding.comment_generate';

    public function __construct(
        private readonly ?SocialAiExecutionService $socialAi = null,
        private readonly ?CanonicalAiTextExecutionService $aiText = null,
        private readonly ?SeedingSocialContextResolver $contextResolver = null,
        private readonly ?SeedingCommentPromptService $promptService = null,
        private readonly ?SeedingCommentGenerateHistoryService $history = null,
    ) {}

    private function resolver(): SeedingSocialContextResolver
    {
        return $this->contextResolver ?? new SeedingSocialContextResolver();
    }

    private function prompts(): SeedingCommentPromptService
    {
        return $this->promptService ?? new SeedingCommentPromptService();
    }

    private function historyStore(): SeedingCommentGenerateHistoryService
    {
        return $this->history ?? new SeedingCommentGenerateHistoryService();
    }

    private function socialAiService(): SocialAiExecutionService
    {
        if ($this->socialAi instanceof SocialAiExecutionService) {
            return $this->socialAi;
        }

        if (function_exists('app')) {
            try {
                return app(SocialAiExecutionService::class);
            } catch (Throwable) {
            }
        }

        $canonicalAi = $this->aiText
            ?? (function_exists('app')
                ? app(CanonicalAiTextExecutionService::class)
                : null);

        return new SocialAiExecutionService($canonicalAi);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function generateFromPayload(array $payload): array
    {
        $mcpContext = $this->resolver()->resolve($payload);

        $social = trim((string) ($payload['social'] ?? $payload['platform'] ?? 'threads'));
        if ($social === '') {
            $social = 'threads';
        }

        $quantity = (int) ($payload['quantity'] ?? $payload['count'] ?? 3);
        $quantity = max(1, min(12, $quantity));

        $topicId = null;
        if (isset($payload['topic_id']) && is_numeric($payload['topic_id'])) {
            $topicId = (int) $payload['topic_id'];
            if ($topicId <= 0) {
                $topicId = null;
            }
        }

        $managerPrompt = $this->prompts()->getPromptBody();
        $finalPrompt = $this->prompts()->renderFinalPrompt($managerPrompt, $mcpContext);

        $aiOutput = null;
        $provider = null;
        $model = null;

        try {
            $result = $this->socialAiService()->generateCommentsDetailed([
                'context' => $mcpContext,
                'business_prompt' => $finalPrompt,
                'social' => $social,
                'quantity' => $quantity,
            ]);

            $comments = $result['comments'];
            $aiOutput = $result['raw_output'] ?? null;
            $provider = $result['provider'] ?? null;
            $model = $result['model'] ?? null;

            // Snapshot must equal the Manager prompt after {{mcp_context}} replacement —
            // not a reconstructed prompt and not a style-augmented copy.
            $this->historyStore()->record([
                'topic_id' => $topicId,
                'social' => $social,
                'quantity' => $quantity,
                'mcp_context' => $mcpContext,
                'final_prompt' => $finalPrompt,
                'ai_output' => is_string($aiOutput) ? $aiOutput : null,
                'provider' => is_string($provider) ? $provider : null,
                'model' => is_string($model) ? $model : null,
                'status' => SeedingCommentGenerateHistoryService::STATUS_SUCCESS,
                'error_message' => null,
            ]);

            return $comments;
        } catch (Throwable $e) {
            $this->historyStore()->record([
                'topic_id' => $topicId,
                'social' => $social,
                'quantity' => $quantity,
                'mcp_context' => $mcpContext,
                'final_prompt' => $finalPrompt,
                'ai_output' => is_string($aiOutput) ? $aiOutput : null,
                'provider' => is_string($provider) ? $provider : null,
                'model' => is_string($model) ? $model : null,
                'status' => SeedingCommentGenerateHistoryService::STATUS_FAILED,
                'error_message' => $e->getMessage() !== '' ? $e->getMessage() : 'Gen comment thất bại.',
            ]);

            if ($e instanceof SocialAiException || $e instanceof RuntimeException) {
                throw $e;
            }

            throw new RuntimeException(
                $e->getMessage() !== '' ? $e->getMessage() : 'Gen comment thất bại.',
                0,
                $e,
            );
        }
    }

    /**
     * Legacy signature backward-compatibility.
     * Profile reference: {@see AiExecutionProfile::TextFast}.
     *
     * @return list<string>
     */
    public function generate(string $fullText, ?string $socialUrl = null, ?string $platform = null, int $count = 5): array
    {
        $count = max(1, min(12, $count));
        $fullText = trim($fullText);
        $socialUrl = $socialUrl ? trim($socialUrl) : null;

        if ($fullText === '' && ($socialUrl === null || $socialUrl === '')) {
            throw new RuntimeException('Thiếu nội dung gốc để gen nội dung seeding.');
        }

        return $this->generateFromPayload([
            'content' => $fullText,
            'social_url' => $socialUrl,
            'platform' => $platform,
            'count' => $count,
        ]);
    }
}
