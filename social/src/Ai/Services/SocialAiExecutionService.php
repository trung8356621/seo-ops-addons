<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Social\Ai\Services;

use App\Models\ApiConnection;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutingException;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Services\CanonicalAiTextExecutionService;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\Social\Ai\Exceptions\SocialAiException;
use Omnichannel\Addons\Social\Ai\SocialAiTaskRegistry;
use Omnichannel\Addons\Social\Ai\Tasks\SocialCommentGenerateTask;
use Throwable;

final class SocialAiExecutionService
{
    /**
     * @param  (callable(string, string, ?AiExecutionProfile, ?AiRoutingContext, array<string, mixed>): array{0: string, 1: array<string, mixed>|null, 2: ?RoutedAiCandidate})|null  $rawExecutor
     */
    public function __construct(
        private readonly ?CanonicalAiTextExecutionService $aiText = null,
        private readonly ?SocialAiTaskRegistry $taskRegistry = null,
        private readonly mixed $rawExecutor = null,
    ) {}

    private function registry(): SocialAiTaskRegistry
    {
        return $this->taskRegistry ?? new SocialAiTaskRegistry();
    }

    /**
     * Generate comments for Social/Seeding.
     *
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    public function generateComments(array $input): array
    {
        $task = $this->registry()->get(SocialCommentGenerateTask::TASK_KEY);
        $normalized = $task->normalizeInput($input);
        $compiledPrompt = $task->buildCompiledPrompt($normalized);

        $quantity = (int) ($normalized['quantity'] ?? 3);
        $maxOutput = min(2048, max(256, $quantity * 180));

        $profile = AiExecutionProfile::tryFrom($task->routingProfile()) ?? AiExecutionProfile::TextFast;
        $effectiveUserId = $this->resolveEffectiveUserId();

        $context = new AiRoutingContext(
            userId: $effectiveUserId,
            hookKey: $task->taskKey(),
            canonicalPromptKey: $task->taskKey(),
            promptTaskType: 'atomic_text',
            modelArea: $profile->value,
        );

        $selectedCandidate = null;

        try {
            if (is_callable($this->rawExecutor)) {
                [$rawOutput, $usage, $selectedCandidate] = ($this->rawExecutor)(
                    $compiledPrompt,
                    $task->taskKey(),
                    $profile,
                    $context,
                    [
                        'quantity' => $quantity,
                        'count' => $quantity,
                        'max_output' => $maxOutput,
                        'desired_output_tokens' => $maxOutput,
                    ],
                );
            } else {
                if ($this->aiText === null) {
                    throw new SocialAiException('AI execution engine chưa được khởi tạo.');
                }
                [$rawOutput, $usage, $selectedCandidate] = $this->aiText->generate(
                    $compiledPrompt,
                    $task->taskKey(),
                    $profile,
                    $context,
                    [
                        'quantity' => $quantity,
                        'count' => $quantity,
                        'max_output' => $maxOutput,
                        'desired_output_tokens' => $maxOutput,
                    ],
                );
            }
        } catch (AiRoutingException $e) {
            $this->log('warning', 'Social AI comment generate: no eligible route', [
                'task_key' => $task->taskKey(),
                'profile' => $profile->value,
                'has_context' => ! empty($normalized['context']),
                'social' => $normalized['social'] ?? 'threads',
                'quantity' => $quantity,
                'effective_user_id' => $effectiveUserId,
                'technical_error' => $e->getMessage(),
            ]);

            throw new SocialAiException('Hiện chưa có model AI khả dụng để Gen comment.', 0, $e);
        } catch (PromptRunException $e) {
            $this->log('warning', 'Social AI comment generate: prompt run failure', [
                'task_key' => $task->taskKey(),
                'profile' => $profile->value,
                'has_context' => ! empty($normalized['context']),
                'social' => $normalized['social'] ?? 'threads',
                'quantity' => $quantity,
                'effective_user_id' => $effectiveUserId,
                'technical_error' => $e->getMessage(),
            ]);

            $message = $e->userMessage();
            if ($message === '' || str_contains($message, 'No active model supports') || str_contains($e->getMessage(), 'No active model supports')) {
                $message = 'Hiện chưa có model AI khả dụng để Gen comment.';
            }
            throw new SocialAiException($message, 0, $e);
        } catch (Throwable $e) {
            $this->log('error', 'Social AI comment generate: unexpected execution error', [
                'task_key' => $task->taskKey(),
                'profile' => $profile->value,
                'has_context' => ! empty($normalized['context']),
                'social' => $normalized['social'] ?? 'threads',
                'quantity' => $quantity,
                'effective_user_id' => $effectiveUserId,
                'technical_error' => $e->getMessage(),
            ]);

            throw new SocialAiException($e->getMessage() !== '' ? $e->getMessage() : 'AI không tạo được bình luận seeding.', 0, $e);
        }

        $comments = $task->validateAndParseOutput((string) $rawOutput, $quantity);

        $this->log('info', 'Social AI comment generate: successfully generated', [
            'task_key' => $task->taskKey(),
            'routing_profile' => $profile->value,
            'selected_route_model' => $selectedCandidate?->model,
            'selected_provider' => $selectedCandidate?->provider,
            'has_context' => ! empty($normalized['context']),
            'social' => $normalized['social'] ?? 'threads',
            'quantity' => count($comments),
        ]);

        return $comments;
    }

    /**
     * Resolves the workspace/account owner user id for routing scoping.
     * Staff/Seeder users route through their account owner's configured AI connections.
     */
    public function resolveEffectiveUserId(): ?int
    {
        $user = null;
        if (function_exists('auth')) {
            try {
                $user = auth()->user();
            } catch (\Throwable) {
                $user = null;
            }
        }

        if ($user instanceof User) {
            $ownerId = $user->accountOwnerId();
            if ($ownerId !== null && $ownerId > 0) {
                // If the owner has an active connection, use the owner
                if ($this->userHasActiveConnection($ownerId)) {
                    return $ownerId;
                }
            }

            if ($this->userHasActiveConnection((int) $user->id)) {
                return (int) $user->id;
            }
        }

        try {
            // Fall back to first user with an active connection in the workspace
            $connectionOwnerId = ApiConnection::query()
                ->where('status', 'active')
                ->whereNotNull('api_key')
                ->value('user_id');

            if ($connectionOwnerId !== null && (int) $connectionOwnerId > 0) {
                return (int) $connectionOwnerId;
            }
        } catch (\Throwable) {
            // In unit tests without database connection
        }

        return $user?->id ? (int) $user->id : null;
    }

    private function userHasActiveConnection(int $userId): bool
    {
        try {
            return ApiConnection::query()
                ->where('status', 'active')
                ->where(static function ($query) use ($userId): void {
                    $query->where('user_id', $userId)->orWhere('is_global', true);
                })
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        try {
            if (class_exists(Log::class) && method_exists(Log::class, $level)) {
                Log::$level($message, $context);
            }
        } catch (\Throwable) {
            // Facade may not be booted in isolated unit tests
        }
    }
}
