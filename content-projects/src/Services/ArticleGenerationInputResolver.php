<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;


use Omnichannel\Addons\Content\Services\ArticleOutlineResolver;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\Content\Support\ArticleGenerationSourceResult;

/**
 * Shared builder cho article {{input}}: raw outline artifact (2 marker sections).
 * Dùng chung CREATE first-run seed, TYPE_REWRITE, content step retry.
 *
 * Contract thật (article.outline.generate@0.1.0):
 * [START_TASK_1_OUTLINE]…[END_TASK_1_OUTLINE]
 * [START_TASK_2_VOCABULARY]…[END_TASK_2_VOCABULARY]
 *
 * Section 2 trong product language = writing instructions / vocabulary.
 */
class ArticleGenerationInputResolver
{
    public const OUTLINE_START = '[START_TASK_1_OUTLINE]';

    public const OUTLINE_END = '[END_TASK_1_OUTLINE]';

    public const VOCABULARY_START = '[START_TASK_2_VOCABULARY]';

    public const VOCABULARY_END = '[END_TASK_2_VOCABULARY]';

    public const OUTLINE_HOOK_KEY = 'article.outline.generate';

    public const REJECT_MESSAGE = 'Không tìm thấy đầy đủ dàn ý và hướng dẫn viết để tạo lại bài.';

    public function __construct(
        private readonly ArticleOutlineResolver $outlineResolver,
    ) {}

    /**
     * Resolve artifact cho article regeneration / rewrite.
     *
     * @throws \InvalidArgumentException
     */
    public function resolveForArticle(SeoArticle $article, ?int $preferRunId = null): ArticleGenerationSourceResult
    {
        $articleId = (int) $article->getKey();
        if ($articleId <= 0) {
            throw new \InvalidArgumentException(self::REJECT_MESSAGE);
        }

        $fromRun = $this->resolveFromOutlineProducerRun($articleId, $preferRunId);
        if ($fromRun instanceof ArticleGenerationSourceResult) {
            return $fromRun;
        }

        $canonical = $this->resolveFromCanonicalMeta($article);
        if ($canonical instanceof ArticleGenerationSourceResult) {
            return $canonical;
        }

        throw new \InvalidArgumentException(self::REJECT_MESSAGE);
    }

    /**
     * Validate + wrap raw string (workflow edge / state meta / test fixture).
     *
     * @throws \InvalidArgumentException
     */
    public function resolveFromRawArtifact(
        string $raw,
        string $sourceType = ArticleGenerationSourceResult::SOURCE_RAW_ARTIFACT,
        ?int $sourceRunId = null,
        ?int $sourceRunItemId = null,
        ?int $sourcePromptResultId = null,
    ): ArticleGenerationSourceResult {
        $parsed = $this->tryParseArtifact($raw);
        if ($parsed === null) {
            throw new \InvalidArgumentException(self::REJECT_MESSAGE);
        }

        return $this->toResult(
            raw: $parsed['raw'],
            outlineSection: $parsed['outline_section'],
            writingSection: $parsed['writing_instructions_section'],
            sourceType: $sourceType,
            sourceRunId: $sourceRunId,
            sourceRunItemId: $sourceRunItemId,
            sourcePromptResultId: $sourcePromptResultId,
        );
    }

    public function tryResolveFromRawArtifact(
        string $raw,
        string $sourceType = ArticleGenerationSourceResult::SOURCE_RAW_ARTIFACT,
        ?int $sourceRunId = null,
        ?int $sourceRunItemId = null,
        ?int $sourcePromptResultId = null,
    ): ?ArticleGenerationSourceResult {
        try {
            return $this->resolveFromRawArtifact(
                $raw,
                $sourceType,
                $sourceRunId,
                $sourceRunItemId,
                $sourcePromptResultId,
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public function isValidArtifact(string $raw): bool
    {
        return $this->tryParseArtifact($raw) !== null;
    }

    /**
     * @return array{
     *     raw: string,
     *     outline_section: string,
     *     writing_instructions_section: string,
     * }|null
     */
    public function tryParseArtifact(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if ($this->looksLikeArticleContent($raw)) {
            return null;
        }

        $outline = $this->extractSection($raw, self::OUTLINE_START, self::OUTLINE_END);
        $vocab = $this->extractSection($raw, self::VOCABULARY_START, self::VOCABULARY_END);

        if ($outline === null || $vocab === null) {
            return null;
        }

        if (trim($outline) === '' || trim($vocab) === '') {
            return null;
        }

        // Giữ nguyên raw (marker + thứ tự) — không strip trước assembler.
        return [
            'raw' => $raw,
            'outline_section' => trim($outline),
            'writing_instructions_section' => trim($vocab),
        ];
    }

    public function looksLikeArticleContent(string $raw): bool
    {
        $raw = trim($raw);
        if ($raw === '') {
            return false;
        }

        if (preg_match('/^\s*<\s*(p|div|h[1-6]|article|section)\b/iu', $raw) === 1) {
            return true;
        }

        if (preg_match('/^\s*\*\*\s*SEO\s*Title\s*:\s*\*\*/iu', $raw) === 1) {
            return true;
        }

        if (preg_match('/^\s*\*\*\s*Meta\s*Description\s*:\s*\*\*/iu', $raw) === 1) {
            return true;
        }

        // Article body thường có cả SEO Title + Meta Description + đoạn dài.
        $hasSeoTitle = preg_match('/\*\*\s*SEO\s*Title\s*:\s*\*\*/iu', $raw) === 1;
        $hasMeta = preg_match('/\*\*\s*Meta\s*Description\s*:\s*\*\*/iu', $raw) === 1;
        if ($hasSeoTitle && $hasMeta && mb_strlen($raw) >= 400) {
            return true;
        }

        // Content-generation markers (nếu model leak) — không phải outline producer.
        if (
            str_contains($raw, '[START_TASK_1_CONTENT]')
            || str_contains($raw, '[START_ARTICLE_CONTENT]')
        ) {
            return true;
        }

        return false;
    }

    /**
     * Step có phải outline producer không (không phải content run mới nhất).
     * Chỉ role / hook / persists_as_outline / structured ports — không title heuristic.
     *
     * @param  array<string, mixed>  $step
     */
    public function isOutlineProducerStep(array $step): bool
    {
        $role = \Omnichannel\Addons\ContentProjects\Enums\WorkflowExecutionRole::tryFromMixed(
            $step['execution_role'] ?? null,
        );
        if ($role === \Omnichannel\Addons\ContentProjects\Enums\WorkflowExecutionRole::ArticleOutlineGenerate) {
            return true;
        }
        if (
            $role === \Omnichannel\Addons\ContentProjects\Enums\WorkflowExecutionRole::ArticleContentGenerate
            || $role === \Omnichannel\Addons\ContentProjects\Enums\WorkflowExecutionRole::ArticleContentImprove
        ) {
            return false;
        }

        $hookKey = trim((string) ($step['hook_key'] ?? ''));
        if ($hookKey === self::OUTLINE_HOOK_KEY) {
            return true;
        }

        if (str_starts_with($hookKey, 'article.content.')) {
            return false;
        }

        if ((bool) ($step['persists_as_outline'] ?? false)) {
            return true;
        }

        $outputs = is_array($step['outputs'] ?? null) ? $step['outputs'] : [];
        if (
            trim((string) ($outputs['task_1_outline'] ?? '')) !== ''
            && trim((string) ($outputs['task_2_vocabulary'] ?? '')) !== ''
        ) {
            return true;
        }

        // Không: title/label heuristic; không: bare artifact làm producer (tránh lấy content mới nhất).
        return false;
    }

    /**
     * @return list<string>
     */
    public function candidatePayloadsFromStep(array $step): array
    {
        $outputs = is_array($step['outputs'] ?? null) ? $step['outputs'] : [];

        $candidates = [
            // Raw trước — ports.total / output giữ marker (preserve_markers=true).
            trim((string) ($outputs['total'] ?? '')),
            trim((string) ($step['output'] ?? '')),
            trim((string) ($outputs['out_main'] ?? '')),
            // Ghép lại từ section ports nếu total bị strip.
            $this->reassembleFromPorts($outputs),
            // Chỉ dùng outline_markdown / out_outline khi vẫn còn marker.
            trim((string) ($step['outline_markdown'] ?? '')),
            trim((string) ($outputs['out_outline'] ?? '')),
        ];

        $unique = [];
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '' || isset($unique[$candidate])) {
                continue;
            }
            $unique[$candidate] = $candidate;
        }

        return array_values($unique);
    }

    /**
     * @param  array<string, mixed>  $outputs
     */
    private function reassembleFromPorts(array $outputs): string
    {
        $outline = trim((string) ($outputs['task_1_outline'] ?? $outputs['out_task_1_outline'] ?? ''));
        $vocab = trim((string) ($outputs['task_2_vocabulary'] ?? $outputs['out_task_2_vocabulary'] ?? ''));
        if ($outline === '' || $vocab === '') {
            return '';
        }

        // Ports đã strip marker — gắn lại đúng contract để article assembler nhận đủ 2 phần.
        return self::OUTLINE_START."\n".$outline."\n".self::OUTLINE_END."\n\n"
            .self::VOCABULARY_START."\n".$vocab."\n".self::VOCABULARY_END;
    }

    private function resolveFromOutlineProducerRun(int $articleId, ?int $preferRunId): ?ArticleGenerationSourceResult
    {
        foreach ($this->fetchSuccessfulRunItems($articleId, $preferRunId) as $item) {
            $steps = is_array($item->output_snapshot['steps'] ?? null)
                ? $item->output_snapshot['steps']
                : [];

            foreach ($steps as $step) {
                if (! is_array($step)) {
                    continue;
                }

                foreach ($this->candidatePayloadsFromStep($step) as $candidate) {
                    $parsed = $this->tryParseArtifact($candidate);
                    if ($parsed === null) {
                        continue;
                    }

                    if (! $this->isOutlineProducerStep($step) && ! $this->isLegacyOutlineArtifactStep($step)) {
                        continue;
                    }

                    $resultId = (int) ($step['result_id'] ?? 0);

                    return $this->toResult(
                        raw: $parsed['raw'],
                        outlineSection: $parsed['outline_section'],
                        writingSection: $parsed['writing_instructions_section'],
                        sourceType: ArticleGenerationSourceResult::SOURCE_RUN_OUTLINE_ARTIFACT,
                        sourceRunId: (int) $item->run_id > 0 ? (int) $item->run_id : null,
                        sourceRunItemId: (int) $item->id > 0 ? (int) $item->id : null,
                        sourcePromptResultId: $resultId > 0 ? $resultId : null,
                    );
                }
            }
        }

        return null;
    }

    /**
     * Legacy project snapshots may lack hook/role/ports but still store the complete
     * two-section outline artifact in the prompt output.
     *
     * @param  array<string, mixed>  $step
     */
    private function isLegacyOutlineArtifactStep(array $step): bool
    {
        $status = trim((string) ($step['status'] ?? ''));
        if ($status !== '' && ! in_array($status, ['completed', 'success', 'succeeded'], true)) {
            return false;
        }

        $hookKey = trim((string) ($step['hook_key'] ?? ''));
        if (str_starts_with($hookKey, 'article.content.')) {
            return false;
        }

        return true;
    }

    /**
     * @return \Illuminate\Support\Collection<int, SeoProjectRunItem>
     */
    protected function fetchSuccessfulRunItems(int $articleId, ?int $preferRunId): \Illuminate\Support\Collection
    {
        $query = SeoProjectRunItem::query()
            ->where('article_id', $articleId)
            ->whereIn('status', [
                SeoProjectRunItemStatus::Success->value,
                SeoProjectRunItemStatus::Failed->value,
            ])
            ->orderByDesc('id')
            ->limit(50);

        if ($preferRunId !== null && $preferRunId > 0) {
            $query->where('run_id', $preferRunId);
        }

        return $query->get(['id', 'run_id', 'output_snapshot', 'action']);
    }

    private function resolveFromCanonicalMeta(SeoArticle $article): ?ArticleGenerationSourceResult
    {
        $markdown = $this->outlineResolver->resolveMarkdown($article);
        if ($markdown === '') {
            return null;
        }

        $parsed = $this->tryParseArtifact($markdown);
        if ($parsed === null) {
            // Heading-only / cleaned-without-markers ≠ full artifact.
            return null;
        }

        return $this->toResult(
            raw: $parsed['raw'],
            outlineSection: $parsed['outline_section'],
            writingSection: $parsed['writing_instructions_section'],
            sourceType: ArticleGenerationSourceResult::SOURCE_CANONICAL_OUTLINE_ARTIFACT,
            sourceRunId: null,
            sourceRunItemId: null,
            sourcePromptResultId: null,
        );
    }

    private function extractSection(string $raw, string $start, string $end): ?string
    {
        $pattern = '/'.preg_quote($start, '/').'(.*?)'.preg_quote($end, '/').'/s';
        if (preg_match($pattern, $raw, $matches) !== 1) {
            return null;
        }

        return (string) ($matches[1] ?? '');
    }

    private function toResult(
        string $raw,
        string $outlineSection,
        string $writingSection,
        string $sourceType,
        ?int $sourceRunId,
        ?int $sourceRunItemId,
        ?int $sourcePromptResultId,
    ): ArticleGenerationSourceResult {
        return new ArticleGenerationSourceResult(
            rawArtifact: $raw,
            sourceType: $sourceType,
            outlineSection: $outlineSection,
            writingInstructionsSection: $writingSection,
            outlineMarkerFound: true,
            writingInstructionsMarkerFound: true,
            artifactVersion: substr(hash('sha256', $raw), 0, 16),
            sourceRunId: $sourceRunId,
            sourceRunItemId: $sourceRunItemId,
            sourcePromptResultId: $sourcePromptResultId,
        );
    }
}
