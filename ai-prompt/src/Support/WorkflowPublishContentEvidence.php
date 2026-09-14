<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

use Omnichannel\Addons\AiPrompt\Services\WorkflowExistingAiOutputService;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Enums\WorkflowArtifactType;
use Omnichannel\Addons\ContentProjects\Enums\WorkflowExecutionRole;

/**
 * Publish/CREATE success requires positive Content evidence — Outline-only is not enough.
 */
final class WorkflowPublishContentEvidence
{
    public const ERROR_CODE = 'content_artifact_missing';

    public const ERROR_MESSAGE = 'Thiếu article content: Outline xong nhưng Content chưa chạy hoặc body không có nội dung thật.';

    public const SKIP_REASON_EXISTING_CONTENT = 'existing_content_reuse';

    public const SKIP_REASON_OUTLINE_ONLY_SCOPE = 'outline_vocabulary_scope';

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return array{ok: bool, message: string, code: string|null}
     */
    public static function evaluate(array $steps, ?SeoArticle $article, bool $requireContent = true): array
    {
        if (! $requireContent) {
            return ['ok' => true, 'message' => '', 'code' => null];
        }

        // Intentional Outline-only scope: Content nodes explicitly skipped.
        if (self::isIntentionalOutlineOnlyScope($steps)) {
            return ['ok' => true, 'message' => '', 'code' => null];
        }

        $contentSteps = self::contentSteps($steps);
        $completed = false;
        $legitimateReuse = false;

        foreach ($contentSteps as $step) {
            $status = strtolower(trim((string) ($step['status'] ?? '')));
            if (in_array($status, ['completed', 'success', 'succeeded'], true)) {
                $completed = true;
                break;
            }
            if ($status === 'skipped' && self::isLegitimateReuseStep($step, $article)) {
                $legitimateReuse = true;
                break;
            }
        }

        if (! $completed && ! $legitimateReuse) {
            // No content-role step at all (absent) or only invalid skips.
            return [
                'ok' => false,
                'message' => self::ERROR_MESSAGE,
                'code' => self::ERROR_CODE,
            ];
        }

        if (! self::hasSemanticPersistedBody($article) && ! self::stepsHaveArticleContentArtifact($steps)) {
            return [
                'ok' => false,
                'message' => self::ERROR_MESSAGE,
                'code' => self::ERROR_CODE,
            ];
        }

        return ['ok' => true, 'message' => '', 'code' => null];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    public static function isIntentionalOutlineOnlyScope(array $steps): bool
    {
        $sawOutlineScopeSkip = false;
        $sawContentExecution = false;

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }
            $reason = trim((string) ($step['skip_reason'] ?? ''));
            if ($reason === self::SKIP_REASON_OUTLINE_ONLY_SCOPE) {
                $sawOutlineScopeSkip = true;
            }
            if (self::isContentStep($step)) {
                $status = strtolower(trim((string) ($step['status'] ?? '')));
                if (in_array($status, ['completed', 'success', 'succeeded', 'failed', 'blocked'], true)) {
                    $sawContentExecution = true;
                }
                if ($status === 'skipped' && $reason === self::SKIP_REASON_EXISTING_CONTENT) {
                    $sawContentExecution = true;
                }
            }
        }

        return $sawOutlineScopeSkip && ! $sawContentExecution;
    }

    /**
     * @param  array<string, mixed>  $step
     */
    public static function isLegitimateReuseStep(array $step, ?SeoArticle $article): bool
    {
        $reason = trim((string) ($step['skip_reason'] ?? ''));
        $message = trim((string) ($step['message'] ?? ''));
        $isReuse = $reason === self::SKIP_REASON_EXISTING_CONTENT
            || $message === 'Bỏ qua AI: bài viết đã có nội dung.';

        if (! $isReuse) {
            return false;
        }

        return self::hasSemanticPersistedBody($article)
            || self::bodyHasSemanticText(trim((string) ($step['output'] ?? '')));
    }

    public static function hasSemanticPersistedBody(?SeoArticle $article): bool
    {
        if (! $article instanceof SeoArticle) {
            return false;
        }

        return self::bodyHasSemanticText(trim((string) ($article->body ?? '')));
    }

    public static function bodyHasSemanticText(string $body): bool
    {
        $body = trim($body);
        if ($body === '') {
            return false;
        }

        if ((new WorkflowExistingAiOutputService)->looksLikeOutlineMarkerPayload($body)) {
            return false;
        }

        $plain = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = str_replace("\u{00A0}", ' ', $plain);
        $plain = trim(preg_replace('/\s+/u', ' ', $plain) ?? '');

        return PromptTextMetrics::wordCount($plain) > 0;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    private static function contentSteps(array $steps): array
    {
        $out = [];
        foreach ($steps as $step) {
            if (is_array($step) && self::isContentStep($step)) {
                $out[] = $step;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private static function isContentStep(array $step): bool
    {
        $role = WorkflowExecutionRole::tryFromMixed($step['execution_role'] ?? null);
        if ($role === WorkflowExecutionRole::ArticleContentGenerate
            || $role === WorkflowExecutionRole::ArticleContentImprove
        ) {
            return true;
        }

        $hook = strtolower(trim((string) ($step['hook_key'] ?? '')));
        if (str_starts_with($hook, 'article.content.')) {
            return true;
        }

        $artifact = trim((string) ($step['artifact_type'] ?? ''));

        return $artifact === WorkflowArtifactType::ArticleContent->value;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private static function stepsHaveArticleContentArtifact(array $steps): bool
    {
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }
            if (trim((string) ($step['artifact_type'] ?? '')) === WorkflowArtifactType::ArticleContent->value) {
                $payload = trim((string) ($step['output'] ?? ''));
                if (self::bodyHasSemanticText($payload)) {
                    return true;
                }
            }
        }

        return false;
    }
}
