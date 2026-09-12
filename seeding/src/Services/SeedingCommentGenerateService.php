<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Services;

use Omnichannel\Addons\AiPrompt\Services\CanonicalAiTextExecutionService;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\Social\Ai\Services\SocialAiExecutionService;
use Omnichannel\Addons\Social\Ai\Tasks\SocialCommentGenerateTask;
use RuntimeException;

/**
 * Stateless seed-content generation via Client Social AI task + shared router.
 * API response still uses { comments: string[] } for contract stability;
 * Flexible Seeding JS adapter maps that to seed_outputs.
 *
 * No Seeding DB writes. No fake persisted prompt rows. No direct first-active-model routing.
 */
final class SeedingCommentGenerateService
{
    /** @deprecated Use SocialCommentGenerateTask::TASK_KEY */
    public const HOOK_KEY = 'seeding.comment_generate';

    public function __construct(
        private readonly ?SocialAiExecutionService $socialAi = null,
        private readonly ?CanonicalAiTextExecutionService $aiText = null,
        private readonly ?SeedingSocialContextResolver $contextResolver = null,
    ) {}

    private function resolver(): SeedingSocialContextResolver
    {
        return $this->contextResolver ?? new SeedingSocialContextResolver();
    }

    private function socialAiService(): SocialAiExecutionService
    {
        if ($this->socialAi instanceof SocialAiExecutionService) {
            return $this->socialAi;
        }

        if (function_exists('app')) {
            try {
                return app(SocialAiExecutionService::class);
            } catch (\Throwable) {}
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
        $context = $this->resolver()->resolve($payload);

        $social = trim((string) ($payload['social'] ?? $payload['platform'] ?? 'threads'));
        if ($social === '') {
            $social = 'threads';
        }

        $quantity = (int) ($payload['quantity'] ?? $payload['count'] ?? 3);
        $quantity = max(1, min(12, $quantity));

        return $this->socialAiService()->generateComments([
            'context' => $context,
            'social' => $social,
            'quantity' => $quantity,
        ]);
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
