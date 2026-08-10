<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleAiHistory;

use Omnichannel\Addons\ContentProjects\Enums\WorkflowArtifactType;
use Omnichannel\Addons\ContentProjects\Enums\WorkflowExecutionRole;
use Omnichannel\Addons\Content\Services\ArticleOutlineResolver;
use Omnichannel\Addons\ContentProjects\Services\Workflow\ArtifactReusePolicy;

/**
 * Fail-closed classifier: quyết định một step/PromptResult trong Article AI History
 * có phải article_outline / article_content hợp lệ hay không — dùng chung cho list & apply.
 *
 * Không đoán mò: chỉ trả về 'typed'/'legacy' khi có tín hiệu rõ ràng (artifact_type,
 * hook_key/execution_role, hoặc marker) VÀ payload vượt qua validation tương ứng.
 * Mọi trường hợp còn lại => 'unknown', can_apply=false.
 */
final class ArticleAiHistoryLegacyClassifier
{
    /** @var list<string> */
    private const SUCCEEDED_STATUSES = ['success', 'succeeded', 'completed'];

    public function __construct(
        private readonly ArticleOutlineResolver $outlineResolver,
        private readonly ArtifactReusePolicy $reusePolicy,
    ) {}

    /**
     * @param  array{hook_key?:?string,execution_role?:?string,artifact_type?:?string,output?:string,outline_markdown?:?string,status?:?string,persists_as_outline?:bool}  $step
     * @return array{artifact_type: ?string, classification: 'typed'|'legacy'|'unknown', can_apply: bool, reason: string, normalized_payload: string}
     */
    public function classify(array $step, ?string $rawOutput): array
    {
        $output = trim((string) ($rawOutput ?? $step['output'] ?? ''));
        $status = strtolower(trim((string) ($step['status'] ?? '')));
        $typedArtifactType = trim((string) ($step['artifact_type'] ?? ''));

        if ($typedArtifactType === WorkflowArtifactType::ArticleOutline->value
            || $typedArtifactType === WorkflowArtifactType::ArticleContent->value
        ) {
            return $this->classifyTyped($typedArtifactType, $status, $output, $step);
        }

        $hookOrRole = strtolower(trim((string) (
            $step['hook_key'] ?? $step['execution_role'] ?? ''
        )));

        $legacyOutline = $this->classifyLegacyOutline($hookOrRole, $step, $output);
        if ($legacyOutline !== null) {
            return $legacyOutline;
        }

        $legacyContent = $this->classifyLegacyContent($hookOrRole, $output);
        if ($legacyContent !== null) {
            return $legacyContent;
        }

        return $this->unknown('ambiguous_step_signature', $output);
    }

    /**
     * Loại markers outline/vocabulary khỏi payload để hiển thị editor-facing preview.
     * Nếu tìm thấy block [START_TASK_N_OUTLINE]...[END_TASK_N_OUTLINE] → chỉ giữ phần trong.
     * Ngược lại chỉ bóc các marker rời rạc, giữ nguyên phần còn lại.
     */
    public function stripOutlineMarkers(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        if (preg_match('/\[START_TASK_\d+_OUTLINE\](.*?)\[END_TASK_\d+_OUTLINE\]/is', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        $stripped = preg_replace('/\[START_TASK_\d+_[A-Z_]+\]/i', '', $text) ?? $text;
        $stripped = preg_replace('/\[END_TASK_\d+_[A-Z_]+\]/i', '', $stripped) ?? $stripped;

        return trim($stripped);
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array{artifact_type: ?string, classification: 'typed'|'legacy'|'unknown', can_apply: bool, reason: string, normalized_payload: string}
     */
    private function classifyTyped(string $artifactType, string $status, string $output, array $step): array
    {
        if (! $this->isSucceededish($status)) {
            return $this->unknown('typed_artifact_status_not_succeeded', $output);
        }

        if ($artifactType === WorkflowArtifactType::ArticleOutline->value) {
            $outlineMarkdown = trim((string) ($step['outline_markdown'] ?? ''));
            $candidate = $outlineMarkdown !== '' ? $outlineMarkdown : $output;
            $stripped = $this->stripOutlineMarkers($candidate);

            if (! $this->outlineResolver->isUsable($stripped) && ! $this->outlineResolver->isUsable($candidate)) {
                return $this->unknown('typed_outline_payload_invalid', $output);
            }

            return [
                'artifact_type' => WorkflowArtifactType::ArticleOutline->value,
                'classification' => 'typed',
                'can_apply' => true,
                'reason' => 'typed_artifact_outline',
                'normalized_payload' => $stripped,
            ];
        }

        if ($this->reusePolicy->looksLikeOutlineMarkerPayload($output) || ! $this->reusePolicy->isValidArticleContentPayload($output)) {
            return $this->unknown('typed_content_payload_invalid', $output);
        }

        return [
            'artifact_type' => WorkflowArtifactType::ArticleContent->value,
            'classification' => 'typed',
            'can_apply' => true,
            'reason' => 'typed_artifact_content',
            'normalized_payload' => $output,
        ];
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array{artifact_type: ?string, classification: 'typed'|'legacy'|'unknown', can_apply: bool, reason: string, normalized_payload: string}|null
     */
    private function classifyLegacyOutline(string $hookOrRole, array $step, string $output): ?array
    {
        $persistsAsOutline = (bool) ($step['persists_as_outline'] ?? false);
        $outlineMarkdown = trim((string) ($step['outline_markdown'] ?? ''));

        $looksLikeOutline = $hookOrRole === WorkflowExecutionRole::ArticleOutlineGenerate->value
            || $persistsAsOutline
            || $outlineMarkdown !== '';

        if (! $looksLikeOutline) {
            return null;
        }

        $candidate = $outlineMarkdown !== '' ? $outlineMarkdown : $output;
        $stripped = $this->stripOutlineMarkers($candidate);

        if (! $this->outlineResolver->isUsable($stripped) && ! $this->outlineResolver->isUsable($candidate)) {
            return $this->unknown('legacy_outline_payload_not_usable', $output);
        }

        return [
            'artifact_type' => WorkflowArtifactType::ArticleOutline->value,
            'classification' => 'legacy',
            'can_apply' => true,
            'reason' => 'legacy_outline_marker_or_role',
            'normalized_payload' => $stripped,
        ];
    }

    /**
     * @return array{artifact_type: ?string, classification: 'typed'|'legacy'|'unknown', can_apply: bool, reason: string, normalized_payload: string}|null
     */
    private function classifyLegacyContent(string $hookOrRole, string $output): ?array
    {
        $looksLikeContent = in_array($hookOrRole, [
            WorkflowExecutionRole::ArticleContentGenerate->value,
            'article.content.rewrite',
        ], true);

        if (! $looksLikeContent) {
            return null;
        }

        if ($this->reusePolicy->looksLikeOutlineMarkerPayload($output)) {
            return $this->unknown('legacy_content_contains_outline_marker', $output);
        }

        if (! $this->reusePolicy->isValidArticleContentPayload($output)) {
            return $this->unknown('legacy_content_payload_invalid', $output);
        }

        return [
            'artifact_type' => WorkflowArtifactType::ArticleContent->value,
            'classification' => 'legacy',
            'can_apply' => true,
            'reason' => 'legacy_content_hook_or_role',
            'normalized_payload' => $output,
        ];
    }

    /**
     * @return array{artifact_type: ?string, classification: 'typed'|'legacy'|'unknown', can_apply: bool, reason: string, normalized_payload: string}
     */
    private function unknown(string $reason, string $output): array
    {
        return [
            'artifact_type' => null,
            'classification' => 'unknown',
            'can_apply' => false,
            'reason' => $reason,
            'normalized_payload' => trim($output),
        ];
    }

    private function isSucceededish(string $status): bool
    {
        return in_array($status, self::SUCCEEDED_STATUSES, true);
    }
}
